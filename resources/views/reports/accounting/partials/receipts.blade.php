<p class="hint">
    Each receipt booklet holds <strong>50</strong> consecutive numbers (for example 2051–2100).
    Enter a first and last number to add every booklet in that range. Assign a booklet to a driver by its
    <strong>starting number</strong>. Returned booklets cannot be assigned again.
</p>

<div class="lab-card deliveries-setup-card">
    <h3 class="section-title">Add receipt booklets</h3>
    <form method="POST" action="{{ route('reports.accounting.receipts.store', request()->query()) }}" class="mini-grid">
        @csrf
        <input type="hidden" name="tab" value="receipts">
        <div>
            <label for="receipt_first_number">First number</label>
            <input type="number" id="receipt_first_number" name="first_number" min="1" step="1" required value="{{ old('first_number') }}">
        </div>
        <div>
            <label for="receipt_last_number">Last number</label>
            <input type="number" id="receipt_last_number" name="last_number" min="1" step="1" required value="{{ old('last_number') }}">
        </div>
        <div class="inline-action-row" style="align-items:flex-end;">
            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Add booklets'])
        </div>
    </form>
</div>

<div class="lab-card deliveries-setup-card">
    <h3 class="section-title">Assign booklet to driver</h3>
    <form method="POST" action="{{ route('reports.accounting.receipts.assign', request()->query()) }}" class="mini-grid">
        @csrf
        <input type="hidden" name="tab" value="receipts">
        <div>
            <label for="assign_start_number">Starting number</label>
            <input type="number" id="assign_start_number" name="start_number" min="1" step="1" required value="{{ old('start_number') }}">
        </div>
        <div>
            <label for="assign_driver_name">Driver name</label>
            <input type="text" id="assign_driver_name" name="driver_name" required maxlength="200" value="{{ old('driver_name') }}" list="receipt-driver-suggestions">
            <datalist id="receipt-driver-suggestions">
                @foreach (($drivers ?? []) as $driver)
                    <option value="{{ $driver->full_name ?? '' }}"></option>
                @endforeach
            </datalist>
        </div>
        <div class="inline-action-row" style="align-items:flex-end;">
            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Assign'])
        </div>
    </form>
</div>

<div class="lab-card deliveries-setup-card">
    <h3 class="section-title">Assigned receipt booklets</h3>
    <table>
        <thead>
        <tr>
            <th>#</th>
            <th class="num">Starting number</th>
            <th class="num">Last number</th>
            <th>Assigned to</th>
            <th>Return date</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse (($receiptBookletsAssigned ?? []) as $index => $booklet)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="num">{{ display_number($booklet->start_number ?? 0) }}</td>
                <td class="num">{{ display_number($booklet->end_number ?? 0) }}</td>
                <td>{{ $booklet->assigned_driver ?? '' }}</td>
                <td>{{ $booklet->returned_at ? \Illuminate\Support\Carbon::parse($booklet->returned_at)->format('Y-m-d H:i') : '—' }}</td>
                <td>
                    <details class="inline-edit-details">
                        <summary class="btn btn--secondary btn--sm">Edit</summary>
                        <form method="POST" action="{{ route('reports.accounting.receipts.update', ['booklet' => (int) $booklet->id, 'tab' => 'receipts']) }}" class="mini-grid" style="margin-top:8px;">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="tab" value="receipts">
                            <div>
                                <label>Driver name</label>
                                <input type="text" name="driver_name" maxlength="200" required value="{{ $booklet->assigned_driver ?? '' }}" list="receipt-driver-suggestions">
                            </div>
                            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Update driver', 'type' => 'submit'])
                        </form>
                        <form method="POST" action="{{ route('reports.accounting.receipts.update', ['booklet' => (int) $booklet->id, 'tab' => 'receipts']) }}" style="margin-top:8px;" onsubmit="return confirm('Unassign this receipt booklet?');">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="tab" value="receipts">
                            <input type="hidden" name="unassign" value="1">
                            <button type="submit" class="btn btn--secondary btn--sm">Unassign</button>
                        </form>
                        <form method="POST" action="{{ route('reports.accounting.receipts.destroy', ['booklet' => (int) $booklet->id, 'tab' => 'receipts']) }}" style="margin-top:8px;" onsubmit="return confirm('Delete this receipt booklet?');">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="tab" value="receipts">
                            <button type="submit" class="btn btn--danger btn--sm">Delete</button>
                        </form>
                    </details>
                    <form method="POST" action="{{ route('reports.accounting.receipts.return', request()->query()) }}" style="margin-top:8px;" onsubmit="return confirm('Mark this receipt booklet as returned?');">
                        @csrf
                        <input type="hidden" name="tab" value="receipts">
                        <input type="hidden" name="booklet_id" value="{{ (int) ($booklet->id ?? 0) }}">
                        <button type="submit" class="btn btn--secondary btn--sm">Returned</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No assigned receipt booklets.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="lab-card deliveries-setup-card">
    <h3 class="section-title">Unassigned receipt booklets</h3>
    <table>
        <thead>
        <tr>
            <th>#</th>
            <th class="num">Starting number</th>
            <th class="num">Last number</th>
            <th>Assigned to</th>
            <th>Return date</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse (($receiptBookletsUnassigned ?? []) as $index => $booklet)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="num">{{ display_number($booklet->start_number ?? 0) }}</td>
                <td class="num">{{ display_number($booklet->end_number ?? 0) }}</td>
                <td class="muted">—</td>
                <td class="muted">—</td>
                <td>
                    <details class="inline-edit-details">
                        <summary class="btn btn--secondary btn--sm">Edit</summary>
                        <form method="POST" action="{{ route('reports.accounting.receipts.update', ['booklet' => (int) $booklet->id, 'tab' => 'receipts']) }}" class="mini-grid" style="margin-top:8px;">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="tab" value="receipts">
                            <div>
                                <label>Starting number</label>
                                <input type="number" name="start_number" min="1" step="1" required value="{{ (int) ($booklet->start_number ?? 0) }}">
                            </div>
                            <div>
                                <label>Last number</label>
                                <input type="number" name="end_number" min="1" step="1" required value="{{ (int) ($booklet->end_number ?? 0) }}">
                            </div>
                            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Update numbers', 'type' => 'submit'])
                        </form>
                        <form method="POST" action="{{ route('reports.accounting.receipts.destroy', ['booklet' => (int) $booklet->id, 'tab' => 'receipts']) }}" style="margin-top:8px;" onsubmit="return confirm('Delete this receipt booklet?');">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="tab" value="receipts">
                            <button type="submit" class="btn btn--danger btn--sm">Delete</button>
                        </form>
                    </details>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No unassigned receipt booklets.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="lab-card deliveries-setup-card">
    <h3 class="section-title">Returned receipt booklets</h3>
    <table>
        <thead>
        <tr>
            <th>#</th>
            <th class="num">Starting number</th>
            <th class="num">Last number</th>
            <th>Assigned to</th>
            <th>Return date</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse (($receiptBookletsReturned ?? []) as $index => $booklet)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="num">{{ display_number($booklet->start_number ?? 0) }}</td>
                <td class="num">{{ display_number($booklet->end_number ?? 0) }}</td>
                <td>{{ $booklet->assigned_driver ?? '' }}</td>
                <td>{{ $booklet->returned_at ? \Illuminate\Support\Carbon::parse($booklet->returned_at)->format('Y-m-d H:i') : '—' }}</td>
                <td>
                    <details class="inline-edit-details">
                        <summary class="btn btn--secondary btn--sm">Edit</summary>
                        <form method="POST" action="{{ route('reports.accounting.receipts.update', ['booklet' => (int) $booklet->id, 'tab' => 'receipts']) }}" class="mini-grid" style="margin-top:8px;">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="tab" value="receipts">
                            <div>
                                <label>Driver name</label>
                                <input type="text" name="driver_name" maxlength="200" required value="{{ $booklet->assigned_driver ?? '' }}" list="receipt-driver-suggestions">
                            </div>
                            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Update driver', 'type' => 'submit'])
                        </form>
                        <form method="POST" action="{{ route('reports.accounting.receipts.update', ['booklet' => (int) $booklet->id, 'tab' => 'receipts']) }}" style="margin-top:8px;" onsubmit="return confirm('Reopen this receipt booklet as assigned?');">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="tab" value="receipts">
                            <input type="hidden" name="undo_return" value="1">
                            <button type="submit" class="btn btn--secondary btn--sm">Reopen as assigned</button>
                        </form>
                        <form method="POST" action="{{ route('reports.accounting.receipts.destroy', ['booklet' => (int) $booklet->id, 'tab' => 'receipts']) }}" style="margin-top:8px;" onsubmit="return confirm('Delete this receipt booklet?');">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="tab" value="receipts">
                            <button type="submit" class="btn btn--danger btn--sm">Delete</button>
                        </form>
                    </details>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No returned receipt booklets yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
