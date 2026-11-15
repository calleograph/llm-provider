<?php

declare(strict_types=1);

namespace App\Llm\Context;

use App\Llm\Prompt\PromptAssembler;

/**
 * Контекст для модели в пределах её реального окна (по образцу context_compressor Hermes, упрощённо):
 *
 *   окно модели − резерв под ответ − запас 5 % = бюджет;
 *   обязательно: инструкции (PromptAssembler) + текущий вопрос — не помещаются → понятная ошибка, а не молчаливая обрезка;
 *   затем сводка ранней части диалога и состояние проекта → источники проекта (целиком, если помещаются; иначе
 *   релевантные вопросу фрагменты без повторов) → свежие сообщения истории от последнего к первому.
 *
 * Сообщения, не вошедшие в бюджет и не покрытые сводкой, помечаются: сводку нужно обновить (needsSummary).
 * Полная история остаётся у пользователя — сжатие касается только того, что уходит в модель.
 * Токены считаются консервативной оценкой (2,5 знака на токен): лучше недобрать, чем переполнить окно.
 */
final class ContextBuilder
{
    public const CHARS_PER_TOKEN = 2.5;
    private const CHUNK_CHARS = 1500;
    private const UNANONYMIZED_STUB = '[Ранее это сообщение было отправлено без обезличивания; повторно оно не передаётся.]';

    public static function tokens(string $text): int
    {
        return $text === '' ? 0 : (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN) + 4;
    }

    /**
     * @param array<string,string> $layers слои PromptAssembler (обезличенные)
     * @param list<array{title:string,text:string}> $sources источники проекта (документы; инструкции проекта — в $layers['project'])
     * @param list<array{id:int,role:string,content:string,unanonymized?:bool}> $history сохранённая история (обезличенная)
     * @param array{text:string,covered_until_id:int}|null $summary
     * @return array{system:string,messages:list<array{role:string,content:string}>,report:array<string,int>,needsSummary:bool,droppedUncovered:int}
     * @throws ContextOverflowException
     */
    public static function build(int $contextWindow, int $maxOutput, array $layers, array $sources, array $history, string $current, ?array $summary, ?string $projectState, ?int $historyCapTokens = null): array
    {
        $budget = $contextWindow - $maxOutput - (int) ceil($contextWindow * 0.05);
        $instructions = PromptAssembler::build($layers);
        $fixed = self::tokens($instructions) + self::tokens($current);
        if ($fixed > $budget) {
            throw new ContextOverflowException($fixed, $budget);
        }
        $rest = $budget - $fixed;

        // История после сводки; сообщения, ушедшие без обезличивания, повторно не передаются никому.
        $covered = $summary['covered_until_id'] ?? 0;
        $candidates = [];
        foreach ($history as $m) {
            if ((int) $m['id'] > $covered) {
                $candidates[] = ['id' => (int) $m['id'], 'role' => $m['role'], 'content' => !empty($m['unanonymized']) ? self::UNANONYMIZED_STUB : (string) $m['content']];
            }
        }
        $historyTotal = array_sum(array_map(static fn (array $m): int => self::tokens($m['content']), $candidates));

        // 1. Сводка и состояние проекта: не больше 20 % остатка.
        $data = [];
        $stateText = self::clip((string) $projectState, (int) ($rest * 0.08));
        $summaryText = self::clip((string) ($summary['text'] ?? ''), (int) ($rest * 0.12));
        if ($stateText !== '') {
            $data[] = ['title' => 'Состояние проекта', 'text' => $stateText];
        }
        if ($summaryText !== '') {
            $data[] = ['title' => 'Сводка ранней части диалога', 'text' => $summaryText];
        }
        $used = self::tokens($stateText) + self::tokens($summaryText) + 40;

        // 2. Источники: целиком, если помещаются в свою долю; иначе — релевантные фрагменты.
        $sourcesTotal = array_sum(array_map(static fn (array $s): int => self::tokens($s['text']), $sources));
        $sourceBudget = max((int) (($rest - $used) * 0.4), $rest - $used - $historyTotal);
        [$sourceText, $sourceTokens, $chunksUsed, $chunksTotal] = $sourcesTotal <= $sourceBudget
            ? [self::joinSources($sources), $sourcesTotal, -1, -1]
            : self::relevant($sources, $current . "\n" . implode("\n", array_map(static fn (array $m): string => $m['content'], array_slice($candidates, -2))), $sourceBudget);
        if ($sourceText !== '') {
            $data[] = ['title' => $chunksUsed < 0 ? 'Источники проекта' : "Источники проекта — {$chunksUsed} наиболее подходящих фрагментов из {$chunksTotal}", 'text' => $sourceText];
        }
        $used += $sourceTokens;

        // 3. История: от свежих к ранним, пока помещается.
        $kept = [];
        $historyTokens = 0;
        $left = $historyCapTokens !== null ? min($rest - $used, $historyCapTokens) : $rest - $used;   // потолок истории администратора — поверх окна
        for ($i = count($candidates) - 1; $i >= 0; $i--) {
            $t = self::tokens($candidates[$i]['content']);
            if ($historyTokens + $t > $left) {
                break;
            }
            $historyTokens += $t;
            array_unshift($kept, $candidates[$i]);
        }
        $dropped = count($candidates) - count($kept);
        if ($dropped > 0 && $summaryText === '') {
            $data[] = ['title' => 'Примечание', 'text' => "Ранняя часть диалога ({$dropped} сообщ.) не поместилась в контекст модели. Если для ответа нужны те сведения — попроси пользователя напомнить."];
        }

        $system = PromptAssembler::build($layers, $data);
        $messages = array_map(static fn (array $m): array => ['role' => $m['role'], 'content' => $m['content']], $kept);
        $messages[] = ['role' => 'user', 'content' => $current];
        $report = [
            'window' => $contextWindow, 'reserve' => $maxOutput, 'budget' => $budget,
            'instructions' => self::tokens($instructions), 'summary' => self::tokens($summaryText) + self::tokens($stateText),
            'sources' => $sourceTokens, 'sources_total' => $sourcesTotal, 'history' => $historyTokens, 'history_messages' => count($kept),
            'history_dropped' => $dropped, 'current' => self::tokens($current),
        ];
        $report['total'] = self::tokens($system) + $historyTokens + $report['current'];
        // Сводку пора обновить: часть истории не вошла, либо непокрытая история уже занимает больше 60 % своей доли.
        $needsSummary = $dropped > 0 || $historyTotal > (int) (($rest - $used) * 0.6);

        return ['system' => $system, 'messages' => $messages, 'report' => $report, 'needsSummary' => $needsSummary && count($candidates) > 4, 'droppedUncovered' => $dropped];
    }

    /** @param list<array{title:string,text:string}> $sources */
    private static function joinSources(array $sources): string
    {
        $out = [];
        foreach ($sources as $i => $s) {
            $out[] = '[Источник ' . ($i + 1) . ': ' . $s['title'] . "]\n" . $s['text'];
        }

        return implode("\n\n", $out);
    }

    /**
     * Фрагменты источников, наиболее близкие вопросу (совпадение основ слов с весом редкости), без повторов,
     * в исходном порядке документов. Первый фрагмент каждого источника получает небольшой бонус (обычно там тема документа).
     *
     * @param list<array{title:string,text:string}> $sources
     * @return array{0:string,1:int,2:int,3:int}
     */
    private static function relevant(array $sources, string $query, int $budget): array
    {
        $chunks = [];
        foreach ($sources as $si => $s) {
            $parts = self::chunk($s['text']);
            foreach ($parts as $ci => $text) {
                $chunks[] = ['s' => $si, 'c' => $ci, 'n' => count($parts), 'title' => $s['title'], 'text' => $text, 'stems' => array_count_values(self::stems($text))];
            }
        }
        $df = [];
        foreach ($chunks as $c) {
            foreach (array_keys($c['stems']) as $st) {
                $df[$st] = ($df[$st] ?? 0) + 1;
            }
        }
        $q = array_unique(self::stems($query));
        $n = max(1, count($chunks));
        foreach ($chunks as &$c) {
            $score = $c['c'] === 0 ? 0.3 : 0.0;
            foreach ($q as $st) {
                if (isset($c['stems'][$st])) {
                    $score += log(1 + $n / $df[$st]) * (1 + log($c['stems'][$st]));
                }
            }
            $c['score'] = $score;
        }
        unset($c);
        usort($chunks, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: [$a['s'], $a['c']] <=> [$b['s'], $b['c']]);
        $picked = [];
        $seen = [];
        $tokens = 0;
        foreach ($chunks as $c) {
            $key = md5(mb_strtolower((string) preg_replace('/\s+/u', ' ', $c['text'])));
            $t = self::tokens($c['text']) + 12;
            if (isset($seen[$key]) || $tokens + $t > $budget) {
                continue;   // повтор того же текста в другом источнике не передаётся дважды
            }
            $seen[$key] = true;
            $tokens += $t;
            $picked[] = $c;
        }
        usort($picked, static fn (array $a, array $b): int => [$a['s'], $a['c']] <=> [$b['s'], $b['c']]);
        // Повторы на уровне абзацев: один и тот же абзац из разных источников или фрагментов передаётся один раз.
        $paras = [];
        $out = [];
        $tokens = 0;
        foreach ($picked as $c) {
            $keep = [];
            foreach (preg_split('/\n{2,}/u', $c['text']) ?: [] as $para) {
                $key = md5(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $para))));
                if (trim($para) !== '' && !isset($paras[$key])) {
                    $paras[$key] = true;
                    $keep[] = $para;
                }
            }
            if ($keep !== []) {
                $block = '[Источник ' . ($c['s'] + 1) . ': ' . $c['title'] . ', фрагмент ' . ($c['c'] + 1) . ' из ' . $c['n'] . "]\n" . implode("\n\n", $keep);
                $tokens += self::tokens($block);
                $out[] = $block;
            }
        }

        return [implode("\n\n", $out), $tokens, count($out), count($chunks)];
    }

    /** @return list<string> */
    private static function chunk(string $text): array
    {
        $out = [];
        $buf = '';
        foreach (preg_split('/\n{2,}/u', $text) ?: [$text] as $para) {
            while (mb_strlen($para) > self::CHUNK_CHARS) {
                if ($buf !== '') {
                    $out[] = $buf;
                    $buf = '';
                }
                $out[] = mb_substr($para, 0, self::CHUNK_CHARS);
                $para = mb_substr($para, self::CHUNK_CHARS);
            }
            if ($buf !== '' && mb_strlen($buf) + mb_strlen($para) > self::CHUNK_CHARS) {
                $out[] = $buf;
                $buf = '';
            }
            $buf .= ($buf === '' ? '' : "\n\n") . $para;
        }
        if (trim($buf) !== '') {
            $out[] = $buf;
        }

        return $out;
    }

    /** Грубые основы слов (первые 6 букв слов от 4 букв) — без морфологии, но устойчиво к окончаниям. @return list<string> */
    private static function stems(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($text), $m);

        return array_map(static fn (string $w): string => mb_substr($w, 0, 6), $m[0]);
    }

    private static function clip(string $text, int $tokens): string
    {
        $max = (int) ($tokens * self::CHARS_PER_TOKEN);

        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, max(0, $max - 1)) . '…';
    }
}
