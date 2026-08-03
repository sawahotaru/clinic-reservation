<?php
// ===== QRコード生成（JIS X 0510 / ISO 18004）— 純PHP・外部依存なし =====
// 2段階認証の otpauth:// URI をQRにするために用意した最小実装。
// Composer も GD 拡張も要らず、出力は SVG（＝画像ライブラリ不要）。
// totp.php と同じ方針で「どのレンタルサーバーでも動く」ことを優先している。
//
// 対応範囲（用途を満たす最小限に絞ってある）:
//   - 8ビットバイトモードのみ（otpauth URI は記号・小文字を含むため英数字モードは使えない）
//   - 誤り訂正レベル M（約15%復元。認証アプリのスキャンには十分）
//   - 型番 1〜20（＝最大666バイト。otpauth URI は長くても300バイト程度）
// 秘密鍵を外部のQR生成APIへ送らずに済むことが、自前実装の一番の目的。

/** 型番ごとの誤り訂正レベルMの構成: [ブロックあたりEC語数, [[ブロック数, データ語数], ...]] */
function _qr_ec_spec(int $ver): array
{
    static $spec = [
        1  => [10, [[1, 16]]],
        2  => [16, [[1, 28]]],
        3  => [26, [[1, 44]]],
        4  => [18, [[2, 32]]],
        5  => [24, [[2, 43]]],
        6  => [16, [[4, 27]]],
        7  => [18, [[4, 31]]],
        8  => [22, [[2, 38], [2, 39]]],
        9  => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
        11 => [30, [[1, 50], [4, 51]]],
        12 => [22, [[6, 36], [2, 37]]],
        13 => [22, [[8, 37], [1, 38]]],
        14 => [24, [[4, 40], [5, 41]]],
        15 => [24, [[5, 41], [5, 42]]],
        16 => [28, [[7, 45], [3, 46]]],
        17 => [28, [[10, 46], [1, 47]]],
        18 => [26, [[9, 43], [4, 44]]],
        19 => [26, [[3, 44], [11, 45]]],
        20 => [26, [[3, 41], [13, 42]]],
    ];
    return $spec[$ver];
}

/** 位置合わせパターンの中心座標（型番ごと） */
function _qr_align_centers(int $ver): array
{
    static $tbl = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46],
        10 => [6, 28, 50], 11 => [6, 30, 54], 12 => [6, 32, 58], 13 => [6, 34, 62],
        14 => [6, 26, 46, 66], 15 => [6, 26, 48, 70], 16 => [6, 26, 50, 74],
        17 => [6, 30, 54, 78], 18 => [6, 30, 56, 82], 19 => [6, 30, 58, 86],
        20 => [6, 34, 62, 90],
    ];
    return $tbl[$ver];
}

/** 型番のデータ語数（全ブロックの合計） */
function _qr_data_capacity(int $ver): int
{
    [, $groups] = _qr_ec_spec($ver);
    $n = 0;
    foreach ($groups as [$blocks, $dataCw]) { $n += $blocks * $dataCw; }
    return $n;
}

// ---- GF(256) 演算（リード・ソロモン用。原始多項式 0x11D） ----

/** 指数表・対数表を1度だけ作って使い回す */
function _qr_gf(): array
{
    static $t = null;
    if ($t !== null) return $t;
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) { $x ^= 0x11D; }
    }
    for ($i = 255; $i < 512; $i++) { $exp[$i] = $exp[$i - 255]; }
    return $t = [$exp, $log];
}

/** 次数 $deg の生成多項式（係数は最高次から） */
function _qr_rs_generator(int $deg): array
{
    [$exp, $log] = _qr_gf();
    $g = [1];
    for ($i = 0; $i < $deg; $i++) {
        $next = array_fill(0, count($g) + 1, 0);
        foreach ($g as $k => $c) {
            $next[$k]     ^= $c;                                    // x を掛ける
            $next[$k + 1] ^= ($c ? $exp[($log[$c] + $i) % 255] : 0); // α^i を掛ける
        }
        $g = $next;
    }
    return $g;
}

/** データ語列から誤り訂正語を計算（多項式剰余） */
function _qr_rs_encode(array $data, int $ecLen): array
{
    [$exp, $log] = _qr_gf();
    $g = _qr_rs_generator($ecLen);
    $res = array_merge($data, array_fill(0, $ecLen, 0));
    $n = count($data);
    for ($i = 0; $i < $n; $i++) {
        $lead = $res[$i];
        if ($lead === 0) continue;
        $ll = $log[$lead];
        for ($j = 0; $j <= $ecLen; $j++) {
            if ($g[$j]) { $res[$i + $j] ^= $exp[($log[$g[$j]] + $ll) % 255]; }
        }
    }
    return array_slice($res, $n, $ecLen);
}

// ---- ビット列 → データ語 ----

/** バイトモードで符号化し、パディングまで済ませたデータ語列を返す */
function _qr_encode_data(string $data, int $ver): array
{
    $capacity = _qr_data_capacity($ver);
    $countBits = $ver <= 9 ? 8 : 16;   // 文字数指示子のビット数（型番で変わる）

    $bits = '';
    $bits .= '0100';                                             // モード指示子: 8ビットバイト
    $bits .= str_pad(decbin(strlen($data)), $countBits, '0', STR_PAD_LEFT);
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }

    // 終端パターン（最大4ビット）→ 8ビット境界へ切り上げ
    $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
    if (strlen($bits) % 8) { $bits .= str_repeat('0', 8 - strlen($bits) % 8); }

    $cw = [];
    for ($i = 0; $i < strlen($bits); $i += 8) { $cw[] = bindec(substr($bits, $i, 8)); }

    // 埋め草語を交互に詰めて容量ちょうどにする
    $pad = [0xEC, 0x11];
    for ($i = 0; count($cw) < $capacity; $i++) { $cw[] = $pad[$i % 2]; }
    return $cw;
}

/** ブロック分割 → 各ブロックのEC語計算 → 規格どおりのインターリーブ */
function _qr_final_codewords(string $data, int $ver): array
{
    [$ecLen, $groups] = _qr_ec_spec($ver);
    $cw = _qr_encode_data($data, $ver);

    $dataBlocks = [];
    $ecBlocks   = [];
    $pos = 0;
    foreach ($groups as [$blocks, $dataCw]) {
        for ($b = 0; $b < $blocks; $b++) {
            $block = array_slice($cw, $pos, $dataCw);
            $pos += $dataCw;
            $dataBlocks[] = $block;
            $ecBlocks[]   = _qr_rs_encode($block, $ecLen);
        }
    }

    $out = [];
    $maxData = max(array_map('count', $dataBlocks));
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($dataBlocks as $b) { if (isset($b[$i])) { $out[] = $b[$i]; } }
    }
    for ($i = 0; $i < $ecLen; $i++) {
        foreach ($ecBlocks as $b) { $out[] = $b[$i]; }
    }
    return $out;
}

// ---- モジュール配置 ----

/** 機能パターン（位置検出・分離・タイミング・位置合わせ・形式情報の予約）を置く */
function _qr_place_function_patterns(array &$m, array &$reserved, int $ver, int $size): void
{
    // 位置検出パターン＋分離パターン（3隅）
    foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$r0, $c0]) {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $r0 + $r; $cc = $c0 + $c;
                if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) continue;
                $inner = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                      || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
                      || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $m[$rr][$cc] = $inner ? 1 : 0;
                $reserved[$rr][$cc] = true;
            }
        }
    }

    // タイミングパターン（6行目・6列目の交互）
    for ($i = 8; $i < $size - 8; $i++) {
        $v = ($i % 2 === 0) ? 1 : 0;
        $m[6][$i] = $v; $reserved[6][$i] = true;
        $m[$i][6] = $v; $reserved[$i][6] = true;
    }

    // 位置合わせパターン（位置検出パターンと重なる組み合わせは置かない）
    $centers = _qr_align_centers($ver);
    $last = count($centers) - 1;
    foreach ($centers as $ri => $r0) {
        foreach ($centers as $ci => $c0) {
            if (($ri === 0 && $ci === 0) || ($ri === 0 && $ci === $last) || ($ri === $last && $ci === 0)) continue;
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $m[$r0 + $r][$c0 + $c] = (max(abs($r), abs($c)) !== 1) ? 1 : 0;
                    $reserved[$r0 + $r][$c0 + $c] = true;
                }
            }
        }
    }

    // 形式情報の領域を予約する（値は後段の _qr_place_format で書き込む）
    for ($i = 0; $i < 9; $i++) {
        if (!isset($reserved[8][$i])) { $m[8][$i] = 0; $reserved[8][$i] = true; }
        if (!isset($reserved[$i][8])) { $m[$i][8] = 0; $reserved[$i][8] = true; }
    }
    for ($i = 0; $i < 8; $i++) {
        $m[8][$size - 1 - $i] = 0; $reserved[8][$size - 1 - $i] = true;
        $m[$size - 1 - $i][8] = 0; $reserved[$size - 1 - $i][8] = true;
    }
    $reserved[$size - 8][8] = true;   // 常に暗のモジュール（値は _qr_place_format で書く）

    // 型番情報の領域を予約（型番7以上のみ。値は _qr_place_version で書く）
    if ($ver >= 7) {
        for ($i = 0; $i < 18; $i++) {
            $r = intdiv($i, 3); $c = $size - 11 + ($i % 3);
            $reserved[$r][$c] = true;
            $reserved[$c][$r] = true;
        }
    }
}

/** 型番情報（型番7以上のみ）を書き込む */
function _qr_place_version(array &$m, int $ver, int $size): void
{
    if ($ver < 7) return;
    $rem = $ver << 12;
    for ($i = 0; $i < 6; $i++) {   // BCH(18,6)
        if ($rem & (1 << (17 - $i))) { $rem ^= 0x1F25 << (5 - $i); }
    }
    $vinfo = ($ver << 12) | $rem;
    for ($i = 0; $i < 18; $i++) {
        $b = ($vinfo >> $i) & 1;
        $r = intdiv($i, 3); $c = $size - 11 + ($i % 3);
        $m[$r][$c] = $b;
        $m[$c][$r] = $b;
    }
}

/** データ語をジグザグに配置（6列目のタイミングパターンは飛ばす） */
function _qr_place_data(array &$m, array $reserved, array $codewords, int $size): void
{
    $bits = '';
    foreach ($codewords as $cw) { $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT); }

    $idx = 0; $up = true;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) { $col--; }   // タイミング列は data 配置の対象外
        for ($i = 0; $i < $size; $i++) {
            $row = $up ? ($size - 1 - $i) : $i;
            for ($c = 0; $c < 2; $c++) {
                $cc = $col - $c;
                if (isset($reserved[$row][$cc])) continue;
                $m[$row][$cc] = ($idx < strlen($bits)) ? (int)$bits[$idx] : 0;
                $idx++;
            }
        }
        $up = !$up;
    }
}

/** マスク条件（0〜7） */
function _qr_mask_bit(int $mask, int $r, int $c): bool
{
    switch ($mask) {
        case 0: return ($r + $c) % 2 === 0;
        case 1: return $r % 2 === 0;
        case 2: return $c % 3 === 0;
        case 3: return ($r + $c) % 3 === 0;
        case 4: return (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0;
        case 5: return (($r * $c) % 2) + (($r * $c) % 3) === 0;
        case 6: return ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0;
        default: return (((($r + $c) % 2) + (($r * $c) % 3)) % 2) === 0;
    }
}

/** マスク後の読み取りにくさを規格の4基準で採点（小さいほど良い） */
function _qr_penalty(array $m, int $size): int
{
    $score = 0;

    // 基準1: 同色の連続5個以上
    for ($k = 0; $k < 2; $k++) {
        for ($i = 0; $i < $size; $i++) {
            $run = 0; $prev = -1;
            for ($j = 0; $j < $size; $j++) {
                $v = $k === 0 ? $m[$i][$j] : $m[$j][$i];
                if ($v === $prev) { $run++; }
                else {
                    if ($run >= 5) { $score += 3 + ($run - 5); }
                    $run = 1; $prev = $v;
                }
            }
            if ($run >= 5) { $score += 3 + ($run - 5); }
        }
    }

    // 基準2: 2×2の同色ブロック
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            $v = $m[$r][$c];
            if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) { $score += 3; }
        }
    }

    // 基準3: 位置検出パターンに似た並び（1011101 の側に空白4）
    $p1 = [1,0,1,1,1,0,1,0,0,0,0];
    $p2 = [0,0,0,0,1,0,1,1,1,0,1];
    for ($k = 0; $k < 2; $k++) {
        for ($i = 0; $i < $size; $i++) {
            $line = [];
            for ($j = 0; $j < $size; $j++) { $line[] = $k === 0 ? $m[$i][$j] : $m[$j][$i]; }
            for ($j = 0; $j + 11 <= $size; $j++) {
                $seg = array_slice($line, $j, 11);
                if ($seg === $p1 || $seg === $p2) { $score += 40; }
            }
        }
    }

    // 基準4: 暗モジュールの比率が50%から離れるほど加点
    $dark = 0;
    foreach ($m as $row) { $dark += array_sum($row); }
    $ratio = $dark * 100 / ($size * $size);
    $score += (int)floor(abs($ratio - 50) / 5) * 10;

    return $score;
}

/** 形式情報（誤り訂正レベルM固定）を書き込む */
function _qr_place_format(array &$m, int $mask, int $size): void
{
    $data = (0b00 << 3) | $mask;     // レベルM = 00
    $rem = $data << 10;
    for ($i = 0; $i < 5; $i++) {     // BCH(15,5)
        if ($rem & (1 << (14 - $i))) { $rem ^= 0x537 << (4 - $i); }
    }
    $fmt = ((($data << 10) | $rem) ^ 0x5412);

    for ($i = 0; $i < 15; $i++) {
        $b = ($fmt >> $i) & 1;
        // 1本目: 左上の縦（列8を上から）→ 左下の縦
        if ($i < 6)      { $m[$i][8] = $b; }
        elseif ($i < 8)  { $m[$i + 1][8] = $b; }         // 行6はタイミングなので1つ飛ばす
        else             { $m[$size - 15 + $i][8] = $b; }
        // 2本目: 右上の横（行8を右から）→ 左上の横
        if ($i < 8)      { $m[8][$size - 1 - $i] = $b; }
        elseif ($i === 8){ $m[8][7] = $b; }              // 列6はタイミングなので1つ飛ばす
        else             { $m[8][14 - $i] = $b; }
    }
    $m[$size - 8][8] = 1;
}

/**
 * 文字列をQRのモジュール行列（0/1の2次元配列・余白なし）にする。
 * 型番20に収まらない長さなら RuntimeException。
 */
function qr_matrix(string $data): array
{
    $len = strlen($data);
    $ver = 0;
    for ($v = 1; $v <= 20; $v++) {
        $countBits = $v <= 9 ? 8 : 16;
        if (_qr_data_capacity($v) * 8 >= 4 + $countBits + $len * 8) { $ver = $v; break; }
    }
    if ($ver === 0) { throw new RuntimeException('QRに収まらないデータ長です: ' . $len); }

    $size = 17 + 4 * $ver;
    $base = array_fill(0, $size, array_fill(0, $size, 0));
    $reserved = [];
    _qr_place_function_patterns($base, $reserved, $ver, $size);
    _qr_place_data($base, $reserved, _qr_final_codewords($data, $ver), $size);

    // 8種のマスクを試し、最も減点の少ないものを採用する。
    // 採点は形式情報・型番情報を書き込む前に行う（qrcode.js 系の一般的な実装と同じ流儀。
    // これらを含めて採点する流儀もあるが、どちらも規格上妥当で、広く使われている
    // 実装と同じ出力になる方を選んだ＝他実装と突き合わせて検証できる）。
    $best = null; $bestScore = PHP_INT_MAX; $bestMask = 0;
    for ($mask = 0; $mask < 8; $mask++) {
        $m = $base;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (isset($reserved[$r][$c])) continue;
                if (_qr_mask_bit($mask, $r, $c)) { $m[$r][$c] ^= 1; }
            }
        }
        $score = _qr_penalty($m, $size);
        if ($score < $bestScore) { $bestScore = $score; $best = $m; $bestMask = $mask; }
    }
    _qr_place_format($best, $bestMask, $size);
    _qr_place_version($best, $ver, $size);
    return $best;
}

/**
 * QRを SVG 文字列で返す（そのまま HTML に埋め込める）。
 * $module = 1モジュールの辺(px)、$quiet = 余白のモジュール数（規格の推奨は4）。
 */
function qr_svg(string $data, int $module = 6, int $quiet = 4, string $alt = 'QRコード'): string
{
    $m = qr_matrix($data);
    $size = count($m);
    $dim  = ($size + $quiet * 2) * $module;

    // 暗モジュールを1本のパスにまとめる（要素数を抑えて描画を軽くする）
    $path = '';
    foreach ($m as $r => $row) {
        foreach ($row as $c => $v) {
            if ($v) {
                $path .= 'M' . (($c + $quiet) * $module) . ' ' . (($r + $quiet) * $module)
                       . 'h' . $module . 'v' . $module . 'h-' . $module . 'z';
            }
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '"'
         . ' viewBox="0 0 ' . $dim . ' ' . $dim . '" role="img" aria-label="' . htmlspecialchars($alt) . '">'
         . '<rect width="' . $dim . '" height="' . $dim . '" fill="#fff"/>'
         . '<path d="' . $path . '" fill="#000"/>'
         . '</svg>';
}
