<section class="panel">
    <div class="panel-header"><h2>Document preferences</h2></div>
    <form action="<?= e(app_base_path()); ?>/api/settings" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="panel-body form-stack">
        <input type="hidden" name="section" value="documents" />
        <div class="form-error" hidden role="alert"></div>
        <div class="field">
            <label for="document-layout">Document design</label>
            <select name="layout_mode" id="document-layout">
                <option value="preserve" <?= $settings['layout_mode'] === 'preserve' ? 'selected' : ''; ?>>Keep my uploaded design</option>
                <option value="simple" <?= $settings['layout_mode'] === 'simple' ? 'selected' : ''; ?>>Use a simple app layout</option>
            </select>
        </div>
        <div class="field">
            <label for="default-format">Default application document format</label>
            <select name="default_format" id="default-format">
                <option value="pdf">PDF</option>
                <option value="docx" <?= $settings[ 'default_format' ] === 'docx' ? 'selected' : ''; ?>>DOCX</option>
            </select>
        </div>
        <div class="soft-note">
            Keeping your design uses your editable DOCX sources, retaining their sections, fonts,
            tables and headers. A PDF CV can be attached unchanged; upload its DOCX source for text
            changes. The simple layout creates a new design from your career profile.
        </div>
        <div class="form-actions">
            <a class="btn" href="<?= e(app_base_path()); ?>/documents">Open document library</a>
            <button class="btn btn-primary">Save document preferences</button>
        </div>
    </form>
</section>
