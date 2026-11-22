{{--
    AI の推論の深さを選ぶ欄の選択肢（全画面で共通。D-105-01・D-105-02）。none は「none（推論なし）」と書く。
    受け取る値：$selected（選んでいる推論の深さ）
--}}
@foreach (['none' => 'none（推論なし）', 'low' => 'low', 'medium' => 'medium', 'high' => 'high'] as $effortValue => $effortLabel)
    <option value="{{ $effortValue }}" @selected($selected === $effortValue)>{{ $effortLabel }}</option>
@endforeach
