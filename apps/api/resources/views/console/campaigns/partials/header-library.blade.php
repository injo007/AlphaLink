<div class="form-group col-md-6 campaign-header-library"
     data-list-url="{{ route('console.campaign-headers.index', $workspace->getKey()) }}"
     data-store-url="{{ route('console.campaign-headers.store', $workspace->getKey()) }}"
     data-show-url-template="{{ route('console.campaign-headers.show', [$workspace->getKey(), '__HEADER_ID__']) }}"
     data-delete-url-template="{{ route('console.campaign-headers.destroy', [$workspace->getKey(), '__HEADER_ID__']) }}"
     data-target-id="{{ $textareaId }}">
    <label for="{{ $idPrefix }}-saved-headers">Saved Headers:</label>
    <div class="input-group">
        <select id="{{ $idPrefix }}-saved-headers" class="form-control" aria-label="Select saved header">
            <option value="">Select Header</option>
        </select>
        <div class="input-group-append">
            <button type="button" class="btn btn-success" data-header-save title="Save Header" aria-label="Save Header">
                <i class="fa fa-save" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-danger" data-header-delete title="Delete Header" aria-label="Delete Header" disabled>
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <div class="small text-muted mt-1" data-header-status role="status" aria-live="polite"></div>
</div>
