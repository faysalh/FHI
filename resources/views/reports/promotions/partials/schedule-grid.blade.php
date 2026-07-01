@php
    use App\Support\ArabicPdfText as Ar;

    $columns = $sheet['columns'] ?? [];
    $cells = $sheet['cells'] ?? [];
    $maxRows = max(1, (int) ($sheet['max_rows'] ?? 0));
    $forPdf = $forPdf ?? false;
@endphp
@if ($columns === [])
    <p class="muted">No working days in this week (all days are Friday or configured holidays).</p>
@else
    <div class="table-scroll">
        <table class="promo-schedule-table">
            <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>
                        {{ $column['label'] ?? '' }}
                        @if (! empty($column['date']))
                            <span class="muted" style="display:block;font-weight:400;font-size:11px;">{{ $column['date'] }}</span>
                        @endif
                    </th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @for ($i = 0; $i < $maxRows; $i++)
                <tr>
                    @foreach ($columns as $column)
                        @php
                            $weekday = (int) ($column['weekday'] ?? 0);
                            $names = $cells[$weekday] ?? [];
                            $name = (string) ($names[$i] ?? '');
                        @endphp
                        <td>
                            @if ($name !== '')
                                @if ($forPdf)
                                    {{ Ar::glyphs($name) }}
                                @else
                                    {{ $name }}
                                @endif
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endfor
            </tbody>
        </table>
    </div>
@endif
