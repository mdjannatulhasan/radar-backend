<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PPS Student Report</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; background: #f3efe7; color: #223042; }
        .page { max-width: 880px; margin: 0 auto; padding: 32px 28px 48px; }
        .panel { background: rgba(255,255,255,.88); border: 1px solid #d8d4ca; border-radius: 24px; padding: 24px; box-shadow: 0 18px 40px rgba(34,48,66,.08); }
        .headline { display: flex; justify-content: space-between; gap: 24px; align-items: start; margin-bottom: 24px; }
        .title { font-size: 34px; line-height: 1; margin: 6px 0 8px; }
        .muted { color: #5f6e7e; }
        .grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 20px; }
        .metric { border: 1px solid #d8d4ca; border-radius: 18px; padding: 16px; background: #fffdf8; }
        .metric strong { font-size: 28px; display: block; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e5e0d7; text-align: left; }
        th { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: #5f6e7e; }
        ul { padding-left: 18px; margin: 10px 0 0; }
        @media print {
            body { background: #fff; }
            .page { padding: 0; }
            .panel { box-shadow: none; border: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="panel">
            <div class="headline">
                <div>
                    <div class="muted">Performance Prediction System</div>
                    <h1 class="title">{{ $student->name }}</h1>
                    <div class="muted">Class {{ $student->class_name }}-{{ $student->section }} · Roll {{ $student->roll_number ?? 'N/A' }}</div>
                </div>
                <div class="muted" style="text-align:right;">
                    <div>Reporting period</div>
                    <strong style="font-size: 20px; color: #223042;">{{ $period }}</strong>
                    <div>Generated {{ $generatedAt }}</div>
                </div>
            </div>

            <div class="grid">
                <div class="metric">
                    <div class="muted">Overall score</div>
                    <strong>{{ number_format($snapshot->overall_score, 1) }}</strong>
                </div>
                <div class="metric">
                    <div class="muted">Academic</div>
                    <strong>{{ number_format($snapshot->academic_score, 1) }}</strong>
                </div>
                <div class="metric">
                    <div class="muted">Attendance</div>
                    <strong>{{ number_format($snapshot->attendance_score, 1) }}%</strong>
                </div>
            </div>

            <p><strong>Summary:</strong> {{ $overallMessage }}</p>

            <h2 style="margin-top: 28px;">Subject Picture</h2>
            <table>
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Average</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subjects as $subject => $data)
                        <tr>
                            <td>{{ $subject }}</td>
                            <td>{{ number_format($data['avg'] ?? 0, 1) }}%</td>
                            <td>
                                @if(($data['avg'] ?? 0) >= 70)
                                    Strong
                                @elseif(($data['avg'] ?? 0) >= 50)
                                    Mixed
                                @else
                                    Needs support
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">No subject breakdown is available for this period.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if($recommendations)
                <h2 style="margin-top: 28px;">Recommendations</h2>
                <ul>
                    @foreach($recommendations as $recommendation)
                        <li>{{ $recommendation['text'] }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</body>
</html>
