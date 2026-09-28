{{--
    アップロードする画像を、送る前にブラウザで縮める（横幅が上限を超える場合だけ。ページの表示を重くしないため。D-32）。
    input[type=file][data-resize] に効く。縮められないブラウザでは、そのまま送る。
--}}
<script>
    (() => {
        const maxWidth = {{ (int) config('blogos.ai.image.max_width') }};
        document.querySelectorAll('input[type=file][data-resize]').forEach((input) => {
            input.addEventListener('change', async () => {
                const file = input.files[0];
                const note = document.getElementById(input.dataset.resize);
                if (! file || ! file.type.startsWith('image/') || typeof DataTransfer === 'undefined') return;
                const bitmap = await createImageBitmap(file).catch(() => null);
                if (! bitmap || bitmap.width <= maxWidth) {
                    if (note) note.textContent = bitmap ? `${bitmap.width}×${bitmap.height}（そのまま送ります）` : '';
                    return;
                }
                const height = Math.round(bitmap.height * maxWidth / bitmap.width);
                const canvas = document.createElement('canvas');
                canvas.width = maxWidth;
                canvas.height = height;
                canvas.getContext('2d').drawImage(bitmap, 0, 0, maxWidth, height);
                const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
                canvas.toBlob((blob) => {
                    if (! blob) return;
                    const resized = new File([blob], file.name.replace(/\.\w+$/, type === 'image/png' ? '.png' : '.jpg'), { type });
                    const transfer = new DataTransfer();
                    transfer.items.add(resized);
                    input.files = transfer.files;
                    if (note) note.textContent = `${bitmap.width}×${bitmap.height} → ${maxWidth}×${height} に縮めて送ります`;
                }, type, 0.9);
            });
        });
    })();
</script>
