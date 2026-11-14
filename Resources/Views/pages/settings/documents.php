<section class="panel">
    <div class="panel-header"><h2>Document preferences</h2></div>
    <form action="<?= e(app_base_path()); ?>/api/settings" data-async class="panel-body form-stack">
        <input type="hidden" name="section" value="documents" />
        <div class="form-error" hidden role="alert"></div>
        <div class="field">
            <label for="default-format">Default application document format</label>
            <select name="default_format" id="default-format">
                <option value="pdf">PDF</option>
                <option value="docx" <?= $settings[ 'default_format' ] === 'docx' ? 'selected' : ''; ?>>DOCX</option>
            </select>
        </div>
        <div class="soft-note">
            Generated CVs use a clean, text-based layout. You can export both formats from your
            career profile and manage the master CV from the library.
        </div>
        <div class="form-actions">
            <a class="btn" href="<?= e(app_base_path()); ?>/documents">Open document library</a>
            <button class="btn btn-primary">Save document preferences</button>
        </div>
    </form>
</section>
