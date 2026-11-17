"""Ответ нейросети → документ Word (.docx) без сторонних библиотек: заголовки, абзацы, списки, жирный и курсив, таблицы,
блоки кода. Разметка — тот же упрощённый Markdown, что показывает чат. Метки обезличивания остаются в тексте как есть."""

from __future__ import annotations

import io
import re
import zipfile
from xml.sax.saxutils import escape

_W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
_INLINE = re.compile(r"(\*\*|__)(.+?)\1|(?<![\w*])(\*|_)(?!\s)(.+?)(?<!\s)\3(?![\w*])|`([^`\n]+)`|\[([^\]\n]+)\]\((https?://[^\s()]+)\)")
_TABLE_SEP = re.compile(r"^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$")
_XML_BAD = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f]")


def _t(text: str) -> str:
    return escape(_XML_BAD.sub("", text))


def _runs(text: str) -> str:
    out, pos = [], 0
    for m in _INLINE.finditer(text):
        out.append(_run(text[pos:m.start()]))
        if m.group(2) is not None:
            out.append(_run(m.group(2), "<w:b/>"))
        elif m.group(4) is not None:
            out.append(_run(m.group(4), "<w:i/>"))
        elif m.group(5) is not None:
            out.append(_run(m.group(5), '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/>'))
        else:
            out.append(_run(f"{m.group(6)} ({m.group(7)})"))
        pos = m.end()
    out.append(_run(text[pos:]))
    return "".join(out)


def _run(text: str, props: str = "") -> str:
    if not text:
        return ""
    rpr = f"<w:rPr>{props}</w:rPr>" if props else ""
    return f'<w:r>{rpr}<w:t xml:space="preserve">{_t(text)}</w:t></w:r>'


def _p(inner: str, style: str = "", indent: int = 0) -> str:
    ppr = (f'<w:pStyle w:val="{style}"/>' if style else "") + (f'<w:ind w:left="{indent}" w:hanging="360"/>' if indent else "")
    return f"<w:p>{f'<w:pPr>{ppr}</w:pPr>' if ppr else ''}{inner}</w:p>"


def _cells(line: str) -> list[str]:
    return [c.strip() for c in line.strip().strip("|").split("|")]


def _table(rows: list[list[str]]) -> str:
    width = max(len(r) for r in rows)
    border = "".join(f'<w:{s} w:val="single" w:sz="4" w:color="999999"/>' for s in ("top", "left", "bottom", "right", "insideH", "insideV"))
    body = []
    for i, row in enumerate(rows):
        cells = "".join(f'<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/></w:tcPr>{_p(_run(c, "<w:b/>") if i == 0 else _runs(c))}</w:tc>'
                        for c in row + [""] * (width - len(row)))
        body.append(f"<w:tr>{cells}</w:tr>")
    grid = f'<w:gridCol w:w="{9355 // width}"/>' * width       # tblGrid обязателен по схеме: без него Word предлагает «восстановить» файл
    return (f'<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>{border}</w:tblBorders></w:tblPr>'
            f'<w:tblGrid>{grid}</w:tblGrid>{"".join(body)}</w:tbl>')


def _body(markdown: str) -> str:
    lines = markdown.replace("\r\n", "\n").split("\n")
    out, i = [], 0
    while i < len(lines):
        line = lines[i]
        if line.strip().startswith("```"):
            i += 1
            while i < len(lines) and not lines[i].strip().startswith("```"):
                out.append(_p(_run(lines[i], '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/>'), "Code"))
                i += 1
            i += 1
            continue
        if "|" in line and i + 1 < len(lines) and _TABLE_SEP.match(lines[i + 1]):
            rows = [_cells(line)]
            i += 2
            while i < len(lines) and "|" in lines[i] and lines[i].strip():
                rows.append(_cells(lines[i]))
                i += 1
            out.append(_table(rows))
            continue
        heading = re.match(r"^(#{1,6})\s+(.*)$", line)
        item = re.match(r"^(\s*)([-*+•]|\d{1,3}[.)])\s+(.*)$", line)
        if heading:
            out.append(_p(_runs(heading[2].strip()), f"Heading{min(len(heading[1]), 3)}"))
        elif item:
            mark = "•" if item[2] in "-*+•" else item[2]
            out.append(_p(_run(mark) + "<w:r><w:tab/></w:r>" + _runs(item[3]), indent=720 + 360 * (len(item[1]) // 2)))
        elif re.match(r"^\s*([-*_])\s*(\1\s*){2,}$", line):
            pass                                            # горизонтальная линия: в документе не нужна
        elif line.strip() == "":
            pass
        else:
            out.append(_p(_runs(re.sub(r"^>\s?", "", line))))
        i += 1
    return "".join(out) or _p("")


_STYLES = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles {_W}>
<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri" w:eastAsia="Calibri"/><w:sz w:val="22"/><w:lang w:val="ru-RU"/></w:rPr></w:rPrDefault>
<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>
<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>
<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="200"/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="160"/><w:outlineLvl w:val="2"/></w:pPr><w:rPr><w:b/><w:sz w:val="24"/></w:rPr></w:style>
<w:style w:type="paragraph" w:styleId="Code"><w:name w:val="Code"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="0"/></w:pPr><w:rPr><w:sz w:val="20"/></w:rPr></w:style>
</w:styles>"""

_PARTS = {
    "[Content_Types].xml": """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>""",
    "_rels/.rels": """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>""",
    "word/_rels/document.xml.rels": """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>""",
    "word/styles.xml": _STYLES,
}


def build_docx(markdown: str) -> bytes:
    document = (f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<w:document {_W}><w:body>{_body(markdown)}'
                '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="850" w:bottom="1134" w:left="1701" '
                'w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>')
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as z:
        for name, data in {**_PARTS, "word/document.xml": document}.items():
            z.writestr(name, data)
    return buf.getvalue()
