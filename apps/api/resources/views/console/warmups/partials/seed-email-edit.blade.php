<form data-seed-form="edit-email" data-email-id="{{ $seedEmail->id }}">
    <div class="form-group"><label for="seed-email-value">Email:</label><input id="seed-email-value" class="form-control form-control-alt" name="email" maxlength="255" value="{{ $seedEmail->email }}" placeholder="Edit Email ..." required></div>
    <div class="seed-form-error alert alert-danger py-2" role="alert" hidden></div>
    <button type="submit" class="btn btn-warning btn-block">EDIT</button>
</form>
