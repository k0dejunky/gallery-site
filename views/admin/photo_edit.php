<?php
$title   = 'Edit Media';
$isVideo = is_video($photo['filename']);
$backUrl = $back !== null ? '/admin/galleries/' . (int) $back : '/admin';
$editUrl = url('/admin/photos/' . (int) $photo['id'] . '/edit');
$thumbD  = config('app.uploads.thumb_width') . 'x' . config('app.uploads.thumb_height');

// Every tool posts to the same endpoint with a hidden operation + back value.
$form = static function (string $operation) use ($editUrl, $back): string {
    return '<form method="post" action="' . e($editUrl) . '" enctype="multipart/form-data">'
        . csrf_field()
        . '<input type="hidden" name="operation" value="' . e($operation) . '">'
        . ($back !== null ? '<input type="hidden" name="back" value="' . (int) $back . '">' : '');
};
?>
<p><a href="<?= url($backUrl) ?>">&larr; Back to gallery</a></p>

<?php if (!$isVideo): ?>
<section class="canvas-editor" data-editor>
    <div class="canvas-stage">
        <canvas id="live-canvas" aria-label="Realtime image preview"></canvas>
        <div class="crop-overlay" data-crop-overlay><div class="crop-selection" data-crop-selection hidden></div></div>
        <span class="canvas-loading" data-loading>Loading preview...</span>
    </div>
    <div class="canvas-toolbar">
        <div class="canvas-actions">
            <button type="button" class="btn btn-sm" data-undo disabled>Undo</button>
            <button type="button" class="btn btn-sm" data-redo disabled>Redo</button>
            <button type="button" class="btn btn-sm btn-outline" data-reset>Reset</button>
            <button type="button" class="btn btn-sm" data-rotate="-90">Rotate left</button>
            <button type="button" class="btn btn-sm" data-rotate="90">Rotate right</button>
            <button type="button" class="btn btn-sm" data-flip="x">Flip horizontal</button>
            <button type="button" class="btn btn-sm" data-flip="y">Flip vertical</button>
            <button type="button" class="btn btn-sm" data-start-crop>Select crop</button>
            <button type="button" class="btn btn-sm" data-apply-crop disabled>Apply crop</button>
            <button type="button" class="btn btn-sm btn-outline" data-cancel-crop hidden>Cancel crop</button>
        </div>
        <div class="canvas-controls">
            <label>Brightness <output data-output="brightness">0</output><input type="range" data-control="brightness" min="-100" max="100" value="0"></label>
            <label>Contrast <output data-output="contrast">0</output><input type="range" data-control="contrast" min="-100" max="100" value="0"></label>
            <label>Sharpen <output data-output="sharpen">0</output><input type="range" data-control="sharpen" min="0" max="100" step="1" value="0"></label>
            <label>Saturation <output data-output="saturation">100%</output><input type="range" data-control="saturation" min="0" max="200" value="100"></label>
            <label>Blur <output data-output="blur">0px</output><input type="range" data-control="blur" min="0" max="12" value="0"></label>
            <label>Grayscale <output data-output="grayscale">0%</output><input type="range" data-control="grayscale" min="0" max="100" value="0"></label>
            <label>Sepia <output data-output="sepia">0%</output><input type="range" data-control="sepia" min="0" max="100" value="0"></label>
            <label>Overlay text <input type="text" data-control="text" maxlength="120" placeholder="Optional text"></label>
            <label>Text color <input type="color" data-control="textColor" value="#ffffff"></label>
            <label>Text size <output data-output="textSize">48px</output><input type="range" data-control="textSize" min="12" max="180" value="48"></label>
        </div>
    </div>
    <form method="post" action="<?= e($editUrl) ?>" data-canvas-form>
        <?= csrf_field() ?>
        <input type="hidden" name="operation" value="canvas">
        <?php if ($back !== null): ?><input type="hidden" name="back" value="<?= (int) $back ?>"><?php endif; ?>
        <input type="hidden" name="canvas_data" data-canvas-data>
        <button type="submit" class="btn" data-save-canvas disabled>Save realtime edit</button>
        <a class="btn btn-outline" href="<?= e(url($backUrl)) ?>">Cancel</a>
        <span class="muted canvas-hint">Adjustments preview instantly. Cancel discards unsaved changes.</span>
    </form>
</section>
<script>
(() => {
    const editor = document.querySelector('[data-editor]');
    const canvas = document.querySelector('#live-canvas');
    const ctx = canvas.getContext('2d', {willReadFrequently: true});
    const source = new Image();
    const controls = {};
    const outputs = {};
    const base = { brightness: 0, contrast: 0, sharpen: 0, saturation: 100, blur: 0, grayscale: 0, sepia: 0, text: '', textColor: '#ffffff', textSize: 48, rotation: 0, flipX: 1, flipY: 1 };
    let state = {...base};
    let undo = [];
    let redo = [];
    let ready = false;
    let cropMode = false;
    let cropStart = null;
    let cropSelection = null;
    let drawPending = false;
    const overlay = editor.querySelector('[data-crop-overlay]');
    const selection = editor.querySelector('[data-crop-selection]');

    editor.querySelectorAll('[data-control]').forEach((input) => {
        controls[input.dataset.control] = input;
        const output = editor.querySelector(`[data-output="${input.dataset.control}"]`);
        if (output) outputs[input.dataset.control] = output;
        input.addEventListener('input', () => {
            snapshot();
            state[input.dataset.control] = input.type === 'range' ? Number(input.value) : input.value;
            scheduleDraw();
        });
    });

    const sync = () => {
        Object.keys(controls).forEach((key) => {
            controls[key].value = state[key];
            if (outputs[key]) outputs[key].textContent = key === 'saturation' || key === 'grayscale' || key === 'sepia' ? `${state[key]}%` : key === 'blur' || key === 'textSize' ? `${state[key]}${key === 'blur' ? 'px' : 'px'}` : state[key];
        });
        editor.querySelector('[data-undo]').disabled = !undo.length;
        editor.querySelector('[data-redo]').disabled = !redo.length;
    };

    const snapshot = () => {
        undo.push({...state});
        if (undo.length > 30) undo.shift();
        redo = [];
    };

    const draw = () => {
        if (!ready) return;
        const quarterTurns = ((state.rotation % 360) + 360) % 360 / 90;
        const rotated = quarterTurns % 2 === 1;
        const w = source.naturalWidth;
        const h = source.naturalHeight;
        canvas.width = rotated ? h : w;
        canvas.height = rotated ? w : h;
        const buffer = document.createElement('canvas');
        buffer.width = rotated ? h : w;
        buffer.height = rotated ? w : h;
        const bufferContext = buffer.getContext('2d');
        bufferContext.save();
        bufferContext.translate(buffer.width / 2, buffer.height / 2);
        bufferContext.rotate(state.rotation * Math.PI / 180);
        bufferContext.scale(state.flipX, state.flipY);
        bufferContext.filter = `brightness(${100 + state.brightness}%) contrast(${100 + state.contrast}%) saturate(${state.saturation}%) blur(${state.blur}px) grayscale(${state.grayscale}%) sepia(${state.sepia}%)`;
        bufferContext.drawImage(source, -w / 2, -h / 2, w, h);
        bufferContext.filter = 'none';
        if (state.text.trim()) {
            bufferContext.fillStyle = state.textColor;
            bufferContext.font = `bold ${state.textSize}px sans-serif`;
            bufferContext.textAlign = 'center';
            bufferContext.textBaseline = 'middle';
            bufferContext.shadowColor = 'rgba(0,0,0,.65)';
            bufferContext.shadowBlur = 6;
            bufferContext.fillText(state.text.trim(), 0, 0, Math.max(1, buffer.width - 40));
        }
        bufferContext.restore();
        const crop = state.crop;
        const sourceX = crop ? crop.x : 0;
        const sourceY = crop ? crop.y : 0;
        const sourceW = crop ? crop.width : buffer.width;
        const sourceH = crop ? crop.height : buffer.height;
        canvas.width = sourceW;
        canvas.height = sourceH;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(buffer, sourceX, sourceY, sourceW, sourceH, 0, 0, sourceW, sourceH);
        if (state.sharpen > 0) sharpen(state.sharpen);
        positionOverlay();
        sync();
        editor.querySelector('[data-save-canvas]').disabled = false;
    };

    // Coalesce rapid slider/pointer events into one paint per animation frame.
    const scheduleDraw = () => {
        if (drawPending) return;
        drawPending = true;
        requestAnimationFrame(() => {
            drawPending = false;
            draw();
        });
    };

    const positionOverlay = () => {
        const canvasRect = canvas.getBoundingClientRect();
        const stageRect = overlay.parentElement.getBoundingClientRect();
        overlay.style.left = `${canvasRect.left - stageRect.left}px`;
        overlay.style.top = `${canvasRect.top - stageRect.top}px`;
        overlay.style.width = `${canvasRect.width}px`;
        overlay.style.height = `${canvasRect.height}px`;
    };

    const sharpen = (strength) => {
        const image = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const sourcePixels = image.data.slice();
        const width = canvas.width;
        const height = canvas.height;
        const amount = Math.max(0, Math.min(100, strength)) / 100;
        const edge = -amount;
        const center = 1 + amount * 4;

        for (let y = 1; y < height - 1; y += 1) {
            for (let x = 1; x < width - 1; x += 1) {
                const offset = (y * width + x) * 4;
                for (let channel = 0; channel < 3; channel += 1) {
                    const centerPixel = sourcePixels[offset + channel] * center;
                    const neighbors = (
                        sourcePixels[((y - 1) * width + x) * 4 + channel]
                        + sourcePixels[((y + 1) * width + x) * 4 + channel]
                        + sourcePixels[(y * width + x - 1) * 4 + channel]
                        + sourcePixels[(y * width + x + 1) * 4 + channel]
                    ) * edge;
                    image.data[offset + channel] = Math.max(0, Math.min(255, centerPixel + neighbors));
                }
            }
        }
        ctx.putImageData(image, 0, 0);
    };

    source.onload = () => {
        ready = true;
        editor.querySelector('[data-loading]').hidden = true;
        draw();
    };
    source.src = <?= json_encode(file_url($photo['filename'])) ?>;

    editor.querySelectorAll('[data-rotate]').forEach((button) => button.addEventListener('click', () => { snapshot(); state.rotation += Number(button.dataset.rotate); scheduleDraw(); }));
    editor.querySelectorAll('[data-flip]').forEach((button) => button.addEventListener('click', () => { snapshot(); state[button.dataset.flip === 'x' ? 'flipX' : 'flipY'] *= -1; scheduleDraw(); }));
    editor.querySelector('[data-reset]').addEventListener('click', () => { snapshot(); state = {...base}; scheduleDraw(); });
    editor.querySelector('[data-undo]').addEventListener('click', () => { if (!undo.length) return; redo.push({...state}); state = undo.pop(); scheduleDraw(); });
    editor.querySelector('[data-redo]').addEventListener('click', () => { if (!redo.length) return; undo.push({...state}); state = redo.pop(); scheduleDraw(); });
    editor.querySelector('[data-start-crop]').addEventListener('click', () => {
        cropMode = true;
        cropSelection = null;
        selection.hidden = true;
        overlay.style.pointerEvents = 'auto';
        editor.querySelector('[data-start-crop]').disabled = true;
        editor.querySelector('[data-cancel-crop]').hidden = false;
    });
    editor.querySelector('[data-cancel-crop]').addEventListener('click', () => {
        cropMode = false;
        cropSelection = null;
        selection.hidden = true;
        overlay.style.pointerEvents = 'none';
        editor.querySelector('[data-start-crop]').disabled = false;
        editor.querySelector('[data-apply-crop]').disabled = true;
        editor.querySelector('[data-cancel-crop]').hidden = true;
    });
    editor.querySelector('[data-apply-crop]').addEventListener('click', () => {
        if (!cropSelection) return;
        snapshot();
        const scaleX = canvas.width / overlay.clientWidth;
        const scaleY = canvas.height / overlay.clientHeight;
        state.crop = {
            x: Math.round(cropSelection.x * scaleX),
            y: Math.round(cropSelection.y * scaleY),
            width: Math.max(1, Math.round(cropSelection.width * scaleX)),
            height: Math.max(1, Math.round(cropSelection.height * scaleY)),
        };
        editor.querySelector('[data-cancel-crop]').click();
        scheduleDraw();
    });
    overlay.addEventListener('pointerdown', (event) => {
        if (!cropMode) return;
        const rect = overlay.getBoundingClientRect();
        cropStart = {x: event.clientX - rect.left, y: event.clientY - rect.top};
        cropSelection = {x: cropStart.x, y: cropStart.y, width: 0, height: 0};
        selection.hidden = false;
        overlay.setPointerCapture(event.pointerId);
    });
    overlay.addEventListener('pointermove', (event) => {
        if (!cropMode || !cropStart) return;
        const rect = overlay.getBoundingClientRect();
        const x = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
        const y = Math.max(0, Math.min(rect.height, event.clientY - rect.top));
        cropSelection = {x: Math.min(cropStart.x, x), y: Math.min(cropStart.y, y), width: Math.abs(x - cropStart.x), height: Math.abs(y - cropStart.y)};
        selection.style.left = `${cropSelection.x}px`;
        selection.style.top = `${cropSelection.y}px`;
        selection.style.width = `${cropSelection.width}px`;
        selection.style.height = `${cropSelection.height}px`;
        editor.querySelector('[data-apply-crop]').disabled = cropSelection.width < 4 || cropSelection.height < 4;
    });
    overlay.addEventListener('pointerup', () => { cropStart = null; });
    window.addEventListener('resize', positionOverlay);
    editor.querySelector('[data-canvas-form]').addEventListener('submit', (event) => {
        if (!ready) { event.preventDefault(); return; }
        editor.querySelector('[data-canvas-data]').value = canvas.toDataURL('image/jpeg', .92);
    });
})();
</script>
<?php endif; ?>
