<?php
/**
 * Staged-upload UI: the drop zone, the pending-count heading and the tile grid.
 *
 * Shared by admin/create.php and admin/manage.php, which had this block (and the
 * ~360-line upload client in upload_client.php) copied verbatim between them -
 * 368 identical lines, including the whole chunked-upload JavaScript.
 *
 * Set these before requiring:
 *   $uploadHeading    string  e.g. 'Files to add' / 'Staged files'
 *   $uploadCountNoun  string  e.g. 'uploaded' / 'staged'
 *   $uploadHint       string  the line under the drop-zone prompt
 *   $uploadHintId     ?string optional id so type radios can update the hint
 *   $uploadAfter      string  optional HTML rendered under the tiles
 *                             (manage.php's "Add staged files" button)
 *   $pendingFiles     array   already in scope on both pages
 */
$uploadHeading   = (string) ($uploadHeading ?? 'Files');
$uploadCountNoun = (string) ($uploadCountNoun ?? 'uploaded');
$uploadHint      = (string) ($uploadHint ?? '');
$uploadHintId    = $uploadHintId ?? null;
$uploadAfter     = (string) ($uploadAfter ?? '');
?>
<div class="drop-zone" id="drop-zone" tabindex="0">
    <div class="dz-icon" aria-hidden="true">&#128228;</div>
    <div class="dz-main">Drop files here or click to upload</div>
    <div class="dz-hint"<?= $uploadHintId !== null ? ' id="' . e($uploadHintId) . '"' : '' ?>><?= e($uploadHint) ?></div>
    <input type="file" id="file-input" name="photos[]" multiple style="display:none">
</div>

<div class="pending-head">
    <h2><?= e($uploadHeading) ?></h2>
    <span class="count"><span id="pending-count">0</span> <?= e($uploadCountNoun) ?></span>
</div>
<div class="pending-tiles" id="pending-tiles"></div>
<?= $uploadAfter ?>
