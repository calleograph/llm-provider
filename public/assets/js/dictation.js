/*
 * Голосовой ввод в поле сообщения:
 * микрофон → PCM 16 кГц (AudioWorklet) → WebSocket ws/dictation (вход по одноразовому билету
 * от api.php) → распознанные фрагменты. Ничего не отправляет сам: текст вставляется в поле,
 * пользователь проверяет и отправляет его сам. Микрофон освобождается сразу после остановки,
 * отмены, ошибки и при отключении устройства. Звук на сервере не сохраняется.
 */
(function (global) {
  'use strict';

  var WORKLET = 'assets/audio/pcm16.worklet.js';

  /**
   * @param {{ticket: function(): Promise<{ticket:string,path:string}>, onFinal: function(number,string), onPhase: function(string,string=), onLevel: function(number)=}} h
   */
  function Dictation(h) {
    this.h = h;
    this.ws = null; this.stream = null; this.ctx = null; this.node = null;
    this.done = null; this.finished = false; this.finals = 0; this.peak = 0;
    this.phase = 'idle';
  }

  Dictation.supported = function () {
    return !!(global.navigator && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && global.AudioWorkletNode && global.WebSocket);
  };

  Dictation.prototype.setPhase = function (p, message) { this.phase = p; this.h.onPhase(p, message); };

  Dictation.prototype.start = function () {
    var self = this;
    if (this.phase === 'starting' || this.phase === 'listening' || this.phase === 'finishing') { return Promise.resolve(); }
    this.finished = false; this.finals = 0; this.peak = 0;
    this.setPhase('starting');
    return navigator.mediaDevices.getUserMedia({
      audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true }
    }).then(function (stream) {
      self.stream = stream;
      var track = stream.getAudioTracks()[0];
      if (track) { track.addEventListener('ended', function () { if (self.phase === 'listening') { self.stop(); } }); }
      return self.h.ticket();
    }, function (e) {
      var name = e && e.name;
      self.fail(name === 'NotAllowedError' ? 'Нет доступа к микрофону: разрешите его в настройках браузера' : name === 'NotFoundError' ? 'Микрофон не найден' : 'Не удалось включить микрофон');
      return null;
    }).then(function (t) {
      if (!t || self.phase !== 'starting') { return; }
      var proto = location.protocol === 'https:' ? 'wss' : 'ws';
      var base = location.pathname.replace(/[^/]*$/, '');
      var ws = new WebSocket(proto + '://' + location.host + base + t.path + '?ticket=' + encodeURIComponent(t.ticket));
      ws.binaryType = 'arraybuffer';
      self.ws = ws;
      ws.onmessage = function (e) { self.onMessage(e.data); };
      ws.onclose = function () {
        if (self.ws !== ws) { return; }
        self.ws = null;
        self.releaseMic();
        if (!self.finished) {
          self.finished = true;
          if (self.done) { self.done(false); }
          if (self.phase !== 'error') { self.setPhase('error', 'Связь с сервером распознавания прервалась. Уже распознанный текст остался в поле.'); }
        }
      };
      ws.onerror = function () { if (self.phase === 'starting') { self.fail('Нет связи с сервером распознавания. Сообщите администратору: прокси сайта должен пропускать WebSocket.'); } };
    }).catch(function (e) {
      self.fail((e && e.message) || 'Не удалось начать диктовку');
    });
  };

  Dictation.prototype.begin = function () {
    var self = this;
    try {
      this.ctx = new AudioContext({ sampleRate: 16000, latencyHint: 'interactive' });
    } catch (e) {
      this.fail('Браузер не смог обработать звук микрофона');
      return;
    }
    var ctx = this.ctx;
    (ctx.state === 'suspended' ? ctx.resume() : Promise.resolve()).then(function () {
      return ctx.audioWorklet.addModule(WORKLET);
    }).then(function () {
      if (!self.stream || self.ctx !== ctx) { return; }
      var src = ctx.createMediaStreamSource(self.stream);
      self.node = new AudioWorkletNode(ctx, 'pcm16', { numberOfInputs: 1, numberOfOutputs: 0 });
      self.node.port.onmessage = function (e) {
        var level = e.data.level;
        if (self.h.onLevel) { self.h.onLevel(level); }
        if (level > self.peak) { self.peak = level; }
        if (self.ws && self.ws.readyState === WebSocket.OPEN && self.phase === 'listening') { self.ws.send(e.data.pcm); }
      };
      src.connect(self.node);
      self.setPhase('listening');
    }).catch(function () {
      self.fail('Браузер не смог обработать звук микрофона');
    });
  };

  Dictation.prototype.onMessage = function (raw) {
    var m;
    try { m = JSON.parse(String(raw)); } catch (e) { return; }
    switch (m.type) {
      case 'ready': this.begin(); break;
      case 'final':
        if (String(m.text || '').trim()) { this.finals++; }
        this.h.onFinal(Number(m.seq), String(m.text || ''));
        break;
      case 'busy': this.h.onPhase(this.phase, String(m.message || 'Распознавание занято — часть фразы пропущена')); break;
      case 'limit': this.stop(); break;
      case 'done': this.finish(true); break;
      case 'error': this.fail(String(m.message || 'Сбой распознавания')); break;
    }
  };

  Dictation.prototype.finish = function (ok) {
    if (this.finished) { return; }
    this.finished = true;
    this.releaseMic();
    try { if (this.ws) { this.ws.close(1000); } } catch (e) { /* закрыт */ }
    this.ws = null;
    if (ok && this.finals === 0 && this.peak > 0.02) {
      this.setPhase('error', 'Речь не распознана: проверьте микрофон и повторите.');
    } else {
      this.setPhase(ok && this.finals > 0 ? 'result' : 'idle');
    }
    if (this.done) { this.done(ok); }
  };

  Dictation.prototype.fail = function (message) {
    this.releaseMic();
    var was = this.finished;
    this.finished = true;
    try { if (this.ws) { this.ws.close(1000); } } catch (e) { /* закрыт */ }
    this.ws = null;
    this.setPhase('error', message);
    if (!was && this.done) { this.done(false); }
  };

  /** Остановить и дождаться окончательного текста (последняя фраза дорезается сервером). */
  Dictation.prototype.stop = function () {
    var self = this;
    if (this.phase !== 'listening' && this.phase !== 'starting') { return Promise.resolve(this.finished); }
    this.releaseMic();
    this.setPhase('finishing');
    var p = new Promise(function (resolve) { self.done = resolve; });
    this.settling = p;
    try { if (this.ws) { this.ws.send(JSON.stringify({ type: 'stop' })); } else { this.finish(false); } } catch (e) { this.finish(false); }
    global.setTimeout(function () { if (!self.finished) { self.fail('Распознавание не ответило вовремя — уже распознанный текст в поле ввода'); } }, 40000);
    return p;
  };

  /** Дождаться конца уже начатой остановки (фаза «finishing»); иначе — сразу. */
  Dictation.prototype.settled = function () { return this.phase === 'finishing' && this.settling ? this.settling : Promise.resolve(); };

  /** Отменить: распознанное до этого остаётся в поле, новые фрагменты не придут. */
  Dictation.prototype.cancel = function () {
    try { if (this.ws) { this.ws.send(JSON.stringify({ type: 'cancel' })); } } catch (e) { /* закрыт */ }
    this.finished = true;
    this.releaseMic();
    try { if (this.ws) { this.ws.close(1000); } } catch (e) { /* закрыт */ }
    this.ws = null;
    this.setPhase('idle');
    if (this.done) { this.done(false); }
  };

  Dictation.prototype.releaseMic = function () {
    try { if (this.node) { this.node.port.close(); this.node.disconnect(); } } catch (e) { /* уже */ }
    this.node = null;
    if (this.stream) { this.stream.getTracks().forEach(function (t) { t.stop(); }); }
    this.stream = null;
    if (this.ctx) { this.ctx.close().catch(function () {}); }
    this.ctx = null;
  };

  /** Вставка фрагмента у курсора: набранное вручную сохраняется, повтор фрагмента (тот же номер) не добавляется. */
  function insertDictated(value, caret, text, seq, seen) {
    var t = String(text || '').trim();
    if (!t || seen[seq]) { return { value: value, caret: caret }; }
    seen[seq] = true;
    var at = Math.max(0, Math.min(caret, value.length));
    var before = value.slice(0, at), after = value.slice(at);
    var lead = before && !/\s$/.test(before) ? ' ' : '';
    var tail = after && !/^\s/.test(after) ? ' ' : '';
    var cap = !before.trim() || /[.!?]\s*$/.test(before);
    var piece = lead + (cap ? t.charAt(0).toUpperCase() + t.slice(1) : t) + tail;
    return { value: before + piece + after, caret: before.length + piece.length - tail.length };
  }

  global.Dictation = Dictation;
  global.insertDictated = insertDictated;
})(window);
