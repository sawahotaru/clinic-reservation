// ===== 認証コード入力の補助 =====
// PCでは日本語IMEが有効なまま入力されがちで、全角数字（１２３）や勝手な変換が混ざる。
// inputmode="numeric" はモバイルのキーパッドを出すだけでIMEまでは切れないため、
// 入力された値をこちらで半角へ正規化する。
//
// 対象は autocomplete="one-time-code" の入力欄。
//   data-otp="digits" … 6桁の数字のみ（2段階認証の有効化画面）
//   指定なし          … 数字＋英字＋ハイフン（ログイン画面。リカバリコードも入るため）

(function () {
  'use strict';

  /** 全角英数字・記号を半角へ。IME経由の入力を救う */
  function toHalfWidth(s) {
    return s
      .replace(/[Ａ-Ｚａ-ｚ０-９]/g, function (c) {
        return String.fromCharCode(c.charCodeAt(0) - 0xFEE0);
      })
      .replace(/[−ー―‐]/g, '-')   // 全角ハイフン類
      .replace(/　/g, '');    // 全角スペース
  }

  document.querySelectorAll('input[autocomplete="one-time-code"]').forEach(function (el) {
    var digitsOnly = el.dataset.otp === 'digits';

    function normalize() {
      var v = toHalfWidth(el.value);
      v = digitsOnly ? v.replace(/\D/g, '').slice(0, 6)
                     : v.replace(/[^0-9A-Za-z-]/g, '');
      if (v !== el.value) {
        // カーソル位置を保つ（末尾入力が大半なので単純な補正で足りる）
        var pos = el.selectionStart - (el.value.length - v.length);
        el.value = v;
        if (el.type === 'text') { try { el.setSelectionRange(pos, pos); } catch (e) {} }
      }
    }

    el.addEventListener('input', normalize);
    el.addEventListener('compositionend', normalize);   // IME確定の直後
    el.addEventListener('paste', function () { setTimeout(normalize, 0); });
  });
})();
