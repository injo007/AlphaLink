<form data-seed-form="clean">
    <div class="form-group"><label for="seed-clean-server">Select Servers:</label><select id="seed-clean-server" class="form-control selectpicker" data-live-search="true" data-size="6" title="Select Servers" required>@foreach ($servers as $server)<option value="{{ $server->id }}">{{ $server->name }} | {{ $server->ip_address }}</option>@endforeach</select></div>
    <div class="form-group"><label for="seed-clean-date">Date:</label><input id="seed-clean-date" type="date" class="form-control" value="{{ $today }}" required></div>
    <div class="seed-form-error alert alert-danger py-2" role="alert" hidden></div>
    <button type="submit" class="btn btn-info btn-block">CLEAN</button>
</form>
