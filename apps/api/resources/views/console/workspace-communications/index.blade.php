@extends('layouts.admin-shell')

@section('title', 'Team Tasks & Messages')

@section('content')
    <div class="workspace-comms-page">
        <header class="workspace-comms-hero">
            <div class="workspace-comms-hero-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M4 5.5h16v13H4z"/><path d="m4.5 7 7.5 6 7.5-6M8 3h8"/></svg>
            </div>
            <div class="workspace-comms-hero-copy">
                <p class="workspace-comms-eyebrow">WORKSPACE SETTINGS <span aria-hidden="true">/</span> TEAM COMMUNICATION</p>
                <h1>Tasks &amp; messages</h1>
                <p>Share focused updates with active operators and admins in {{ $workspace->name }}.</p>
            </div>
            <div class="workspace-comms-access"><span class="workspace-comms-status-dot"></span> Admin controlled</div>
        </header>

        @if (session('communication_status'))
            <div class="workspace-comms-alert" role="status">{{ session('communication_status') }}</div>
        @endif
        @if ($errors->any())
            <div class="workspace-comms-alert workspace-comms-alert-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <section class="workspace-comms-summary" aria-label="Team communication overview">
            <article><span class="workspace-comms-summary-icon">✦</span><div><span>Published updates</span><strong>{{ $communications->total() }}</strong><small>In this workspace</small></div></article>
            <article><span class="workspace-comms-summary-icon workspace-comms-summary-blue">✓</span><div><span>Audience options</span><strong>2</strong><small>Whole team or one operator</small></div></article>
            <article><span class="workspace-comms-summary-icon workspace-comms-summary-green">↗</span><div><span>Delivery</span><strong>In app</strong><small>Header inbox and bell</small></div></article>
        </section>

        <div class="workspace-comms-grid">
            <section class="workspace-comms-card workspace-comms-compose" aria-labelledby="workspace-comms-compose-title">
                <div class="workspace-comms-card-heading">
                    <div><p class="workspace-comms-eyebrow">CREATE UPDATE</p><h2 id="workspace-comms-compose-title">Send to your team</h2></div>
                    <span class="workspace-comms-heading-mark" aria-hidden="true">＋</span>
                </div>
                <p class="workspace-comms-card-intro">Tasks and messages appear in the header inbox. Notifications appear in the bell.</p>

                <form method="post" action="{{ route('clean.console.workspace-communications.store') }}" class="workspace-comms-form" data-workspace-comms-form>
                    @csrf
                    <label class="workspace-comms-field"><span>Update type</span>
                        <select name="kind" required>
                            <option value="task" @selected(old('kind', 'task') === 'task')>Task</option>
                            <option value="message" @selected(old('kind') === 'message')>Message</option>
                            <option value="notification" @selected(old('kind') === 'notification')>Notification</option>
                        </select>
                        <small data-kind-help>A task appears in the team inbox with an optional due date and completion status.</small>
                    </label>
                    <label class="workspace-comms-field"><span>Title</span><input name="title" type="text" maxlength="140" minlength="3" required value="{{ old('title') }}" placeholder="A clear, short title"></label>
                    <label class="workspace-comms-field"><span>Details</span><textarea name="body" rows="5" maxlength="5000" minlength="3" required placeholder="Add the context and next steps…">{{ old('body') }}</textarea><small>Up to 5,000 characters.</small></label>
                    <div class="workspace-comms-field"><span>Audience</span>
                        <div class="workspace-comms-audience-options">
                            <label><input type="radio" name="audience" value="team" @checked(old('audience', $selectedOperatorId ? 'operator' : 'team') === 'team')><span><strong>Entire team</strong><small>Active admins and operators</small></span></label>
                            <label><input type="radio" name="audience" value="operator" @checked(old('audience', $selectedOperatorId ? 'operator' : 'team') === 'operator')><span><strong>One operator</strong><small>Private to a selected operator</small></span></label>
                        </div>
                    </div>
                    <label class="workspace-comms-field" data-operator-field @if (old('audience', $selectedOperatorId ? 'operator' : 'team') !== 'operator') hidden @endif><span>Operator</span>
                        <select name="operator_id" data-operator-select @if (old('audience', $selectedOperatorId ? 'operator' : 'team') === 'operator') required @endif>
                            <option value="">Select an active operator</option>
                            @foreach ($operators as $operator)
                                <option value="{{ $operator->id }}" @selected((string) old('operator_id', $selectedOperatorId) === (string) $operator->id)>{{ $operator->name ?: $operator->username }} · {{ '@'.$operator->username }}</option>
                            @endforeach
                        </select>
                        @if ($operators->isEmpty())<small>No active operators are assigned to this workspace yet.</small>@endif
                    </label>
                    <label class="workspace-comms-field" data-due-field @if (old('kind', 'task') !== 'task') hidden @endif><span>Due date <em>Optional</em></span><input type="datetime-local" name="due_at" value="{{ old('due_at') }}"></label>
                    <button class="workspace-comms-submit" type="submit"><span aria-hidden="true">↗</span> Publish update</button>
                </form>
            </section>

            <section class="workspace-comms-card workspace-comms-history" aria-labelledby="workspace-comms-history-title">
                <div class="workspace-comms-card-heading">
                    <div><p class="workspace-comms-eyebrow">RECENT ACTIVITY</p><h2 id="workspace-comms-history-title">Published updates</h2></div>
                    <span class="workspace-comms-record-count">{{ $communications->total() }} total</span>
                </div>
                <div class="workspace-comms-record-list">
                    @forelse ($communications as $communication)
                        <article class="workspace-comms-record">
                            <div class="workspace-comms-record-topline">
                                <span class="workspace-comms-kind workspace-comms-kind-{{ $communication->kind }}">{{ ucfirst($communication->kind) }}</span>
                                <time datetime="{{ $communication->created_at->toIso8601String() }}">{{ $communication->created_at->format('M j, Y · H:i') }}</time>
                            </div>
                            <h3>{{ $communication->title }}</h3>
                            <p class="workspace-comms-record-body">{{ $communication->body }}</p>
                            <div class="workspace-comms-record-meta">
                                <span><strong>To</strong> {{ $communication->audience === 'team' ? 'Entire active team' : ($communication->targetOperator?->name ?? 'Operator') }}</span>
                                <span><strong>{{ $communication->recipient_count }}</strong> recipients</span>
                                @if ($communication->kind === 'task')<span><strong>{{ $communication->open_task_count }}</strong> open</span>@endif
                                <span><strong>{{ $communication->unread_count }}</strong> unread</span>
                            </div>
                            <div class="workspace-comms-record-footer"><span>Posted by {{ $communication->author?->name ?? 'Workspace admin' }}</span>
                                <form method="post" action="{{ route('clean.console.workspace-communications.archive', ['communication' => $communication->id]) }}" onsubmit="return confirm('Archive this update for everyone?')">@csrf @method('PATCH')<button type="submit" class="workspace-comms-archive">Archive</button></form>
                            </div>
                        </article>
                    @empty
                        <div class="workspace-comms-empty"><span aria-hidden="true">✦</span><h3>No updates yet</h3><p>Publish a task, message, or notification. It will appear in the recipients’ header.</p></div>
                    @endforelse
                </div>
                @if ($communications->hasPages())<nav class="workspace-comms-pagination" aria-label="Published update pages">{{ $communications->links() }}</nav>@endif
            </section>
        </div>
    </div>

    <script>
        (() => {
            const form = document.querySelector('[data-workspace-comms-form]');
            if (!form) return;
            const operatorField = form.querySelector('[data-operator-field]');
            const operatorSelect = form.querySelector('[data-operator-select]');
            const dueField = form.querySelector('[data-due-field]');
            const kindSelect = form.querySelector('[name="kind"]');
            const syncForm = () => {
                const audience = form.querySelector('[name="audience"]:checked')?.value;
                const isTask = kindSelect.value === 'task';
                operatorField.hidden = audience !== 'operator';
                operatorSelect.required = audience === 'operator';
                dueField.hidden = !isTask;
                const kindHelp = form.querySelector('[data-kind-help]');
                kindHelp.textContent = isTask
                    ? 'A task appears in the team inbox with an optional due date and completion status.'
                    : kindSelect.value === 'notification'
                        ? 'A notification appears in the recipients’ bell menu.'
                        : 'A message appears in the recipients’ team inbox.';
            };
            form.querySelectorAll('[name="audience"]').forEach((input) => input.addEventListener('change', syncForm));
            kindSelect.addEventListener('change', syncForm);
            syncForm();
        })();
    </script>
@endsection
