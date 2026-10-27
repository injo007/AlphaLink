<form data-seed-form="upload" data-seed-id="{{ $seedList->id }}">
    <div class="form-group"><label for="seed-emails">Emails</label><textarea id="seed-emails" class="form-control form-control-alt" name="emails" rows="10" placeholder="Enter Emails.." required></textarea></div>
    <div class="seed-form-error alert alert-danger py-2" role="alert" hidden></div>
    <button type="submit" class="btn btn-info btn-block">SAVE</button>
</form>
