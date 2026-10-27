@php
    $placeholderRows = is_array($rows ?? null) ? array_values($rows) : [];
@endphp

<div class="col-12">
    <div class="block block-rounded" data-dms-placeholder-editor data-name-prefix="send_setup[placeholder_inputs]">
        <div class="block-header">
            <h3 class="block-title">PLACEHOLDERS</h3>
            <div class="block-options d-flex align-items-center">
                <div class="custom-control custom-switch custom-control-inline">
                    <input type="checkbox" class="custom-control-input" id="{{ $idPrefix }}-placeholder-combination" name="send_setup[placeholder_combination]" value="1" @checked(filter_var($combination ?? false, FILTER_VALIDATE_BOOLEAN))>
                    <label class="custom-control-label" for="{{ $idPrefix }}-placeholder-combination">COMBINATION</label>
                </div>
                <div class="custom-control custom-switch custom-control-inline">
                    <input type="checkbox" class="custom-control-input" id="{{ $idPrefix }}-placeholder-all-combination" name="send_setup[placeholder_all_combination]" value="1" @checked(filter_var($allCombination ?? false, FILTER_VALIDATE_BOOLEAN))>
                    <label class="custom-control-label" for="{{ $idPrefix }}-placeholder-all-combination">ALL COMBINATION</label>
                </div>
            </div>
        </div>
        <div class="block-content">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-2">
                    <thead><tr><th scope="col">PLACEHOLDER</th><th scope="col">ROTATE EVERY</th><th scope="col" class="text-right">ACTION</th></tr></thead>
                    <tbody data-placeholder-rows>
                        @foreach ($placeholderRows as $index => $row)
                            <tr data-placeholder-row>
                                <td><textarea class="form-control form-control-sm" name="send_setup[placeholder_inputs][{{ $index }}][data_text]" rows="2" maxlength="50000" aria-label="Placeholder {{ $index + 1 }} values" placeholder="One value per line">{{ $row['data_text'] ?? implode("\n", $row['data'] ?? []) }}</textarea></td>
                                <td><input class="form-control form-control-sm" name="send_setup[placeholder_inputs][{{ $index }}][rotate_every]" type="number" min="1" max="2147483647" value="{{ $row['rotate_every'] ?? 1 }}" aria-label="Placeholder {{ $index + 1 }} rotate every" required></td>
                                <td class="text-right"><button class="btn btn-sm btn-alt-danger" type="button" data-placeholder-remove aria-label="Remove placeholder {{ $index + 1 }}"><i class="fa fa-times" aria-hidden="true"></i></button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button class="btn btn-sm btn-alt-primary" type="button" data-placeholder-add><i class="fa fa-plus mr-1" aria-hidden="true"></i> Add placeholder</button>
            <p class="small text-muted mt-2 mb-0">Each row supplies newline-separated values for <code>[placeholder1]</code>, <code>[placeholder2]</code>, and so on. Campaign Test uses the current row value and rotates it after the selected number of test recipients. Combination fanout and the full campaign sender remain unimplemented.</p>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script src="{{ asset('alphalink/dms-placeholder-editor.js') }}" defer></script>
    @endpush
@endonce
