{{--
    AI のモデルを選ぶ欄の選択肢（全画面で共通。D-105-01・D-105-02）。
    「gpt-6-luna（入力 $0.1・出力 $0.5）」の形（料金は1Mトークンあたりの米ドル）。選べる推論の深さを data-efforts に持つ。
    選ぶ欄に data-model="名前"、推論の深さの欄に同じ名前の data-effort を付けると、モデルで選べない推論の深さを選べなくする（layouts/header の JavaScript）。
    受け取る値：$models（App\Services\Ai\AiApiPolicy::models()）、$selected（選んでいるモデル）
--}}
@foreach ($models as $name => $price)
    <option value="{{ $name }}" data-efforts="{{ implode(',', $price['efforts']) }}" @selected($selected === $name)>{{ $name }}（入力 ${{ $price['input'] }} / 出力 ${{ $price['output'] }}）</option>
@endforeach
