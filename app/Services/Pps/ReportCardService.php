<?php

namespace App\Services\Pps;

use App\Models\Pps\ExamDefinition;
use App\Models\Pps\PretestMark;
use App\Models\Pps\ResultSummary;
use App\Models\Pps\TermMark;
use App\Models\Student;
use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

class ReportCardService
{
    /**
     * Generate a report card PDF for one student.
     * Detects Format A (class ≤10) or Format B (class ≥11) automatically.
     *
     * @return string raw PDF bytes
     */
    public function generate(int $studentId, int $examId): string
    {
        $student = Student::query()->with('stream')->findOrFail($studentId);
        $exam    = ExamDefinition::query()->findOrFail($examId);
        $summary = ResultSummary::query()
            ->where('exam_id', $examId)
            ->where('student_id', $studentId)
            ->first();

        $classLevel = (int) $student->class_name;

        $html = $classLevel >= 11
            ? $this->buildFormatB($student, $exam, $summary)
            : $this->buildFormatA($student, $exam, $summary);

        return $this->renderPdf($html);
    }

    /**
     * Generate a tabulation sheet PDF for all students in an exam+class+section.
     *
     * @return string raw PDF bytes
     */
    public function generateTabulation(int $examId): string
    {
        $exam = ExamDefinition::query()->findOrFail($examId);
        $classLevel = (int) $exam->class_name;

        $students = Student::query()
            ->where('class_name', $exam->class_name)
            ->where('section', $exam->section)
            ->orderBy('roll_number')
            ->get();

        $html = $classLevel >= 11
            ? $this->buildTabulationB($students, $exam)
            : $this->buildTabulationA($students, $exam);

        return $this->renderPdf($html, 'L'); // Landscape for tabulation
    }

    // ─── Format A — Classes 4–10 ─────────────────────────────────────────────

    private function buildFormatA(Student $student, ExamDefinition $exam, ?ResultSummary $summary): string
    {
        $marks = TermMark::query()
            ->where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->with('subject')
            ->orderBy('subject_id')
            ->get();

        $isSecondTerm = str_contains(strtolower($exam->term ?? ''), '2nd')
            || str_contains(strtolower($exam->title ?? ''), '2nd');

        $subjectRows = '';
        foreach ($marks as $m) {
            $vtCols = $isSecondTerm
                ? "<td>{$this->fmt($m->vt)}</td><td>{$this->fmt($m->vt_con)}</td>"
                : '<td>—</td><td>—</td>';

            $subjectRows .= "
            <tr>
                <td class='subject'>{$m->subject->name}</td>
                <td>{$this->fmt($m->spot_test)}</td>
                <td>{$this->fmt($m->spot_test_con)}</td>
                <td>{$this->fmt($m->class_test2)}</td>
                <td>{$this->fmt($m->class_test2_con)}</td>
                <td>{$this->fmt($m->attendance)}</td>
                <td>{$this->fmt($m->term_marks)}</td>
                <td>{$this->fmt($m->term_con)}</td>
                {$vtCols}
                <td class='total'>{$this->fmt($m->total_obtained)}</td>
                <td class='grade'>{$m->letter_grade}</td>
                <td>{$this->fmt($m->highest_marks)}</td>
            </tr>";
        }

        $vtHeader = $isSecondTerm
            ? '<th>VT</th><th>VT CON</th>'
            : '<th>VT</th><th>VT CON</th>';

        $promotionText   = $summary?->is_promoted === true ? 'Promoted' : ($summary?->is_promoted === false ? 'Not Promoted' : '—');
        $totalObtained   = $this->fmt($summary?->total_marks_obtained);
        $totalFull       = $this->fmt($summary?->total_marks_full);
        $gpa             = $this->fmt($summary?->gpa);
        $letterGrade     = $summary?->letter_grade     ?? '—';
        $classPos        = $summary?->class_position   ?? '—';
        $totalStudents   = $summary?->total_students_in_class ?? '—';
        $discipline      = $summary?->discipline       ?? '—';
        $handwriting     = $summary?->handwriting      ?? '—';
        $workingDays     = $summary?->total_working_days ?? '—';
        $presence        = $summary?->total_presence   ?? '—';

        return $this->wrapHtml("
            {$this->schoolHeader()}
            {$this->studentInfoBar($student, $exam)}

            <table class='marks-table'>
                <thead>
                    <tr>
                        <th class='subject'>Subject</th>
                        <th>Spot<br>Test</th><th>Spot<br>CON</th>
                        <th>CT-2</th><th>CT-2<br>CON</th>
                        <th>Att</th>
                        <th>Term<br>Marks</th><th>Term<br>CON</th>
                        {$vtHeader}
                        <th>Total</th><th>Grade</th><th>Highest</th>
                    </tr>
                </thead>
                <tbody>{$subjectRows}</tbody>
            </table>

            <div class='summary-row'>
                <div class='summary-block'>
                    <span class='label'>Total Marks</span>
                    <span class='value'>{$totalObtained} / {$totalFull}</span>
                </div>
                <div class='summary-block'>
                    <span class='label'>GPA</span>
                    <span class='value'>{$gpa}</span>
                </div>
                <div class='summary-block'>
                    <span class='label'>Grade</span>
                    <span class='value'>{$letterGrade}</span>
                </div>
                <div class='summary-block'>
                    <span class='label'>Position</span>
                    <span class='value'>{$classPos} / {$totalStudents}</span>
                </div>
            </div>

            <div class='bottom-row'>
                <div><span class='label'>Discipline:</span> {$discipline}</div>
                <div><span class='label'>Handwriting:</span> {$handwriting}</div>
                <div><span class='label'>Working Days:</span> {$workingDays}</div>
                <div><span class='label'>Presence:</span> {$presence}</div>
                <div><span class='label'>Result:</span> <strong>{$promotionText}</strong></div>
            </div>

            {$this->gradeTable()}
            {$this->signatureBlock()}
        ");
    }

    // ─── Format B — Class 11–12 (Pre-Test) ───────────────────────────────────

    private function buildFormatB(Student $student, ExamDefinition $exam, ?ResultSummary $summary): string
    {
        $marks = PretestMark::query()
            ->where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->with('subject')
            ->orderBy('subject_id')
            ->get();

        $subjectRows = '';
        foreach ($marks as $m) {
            $promoGrade = $m->promotion_grade ?? '—';
            $subjectRows .= "
            <tr>
                <td class='subject'>{$m->subject->name}</td>
                <td>{$this->fmt($m->ct)}</td>
                <td>{$this->fmt($m->attendance)}</td>
                <td>{$this->fmt($m->cq)}</td>
                <td>{$this->fmt($m->cq_con)}</td>
                <td>{$this->fmt($m->mcq)}</td>
                <td>{$this->fmt($m->mcq_con)}</td>
                <td class='total'>{$this->fmt($m->total_obtained)}</td>
                <td class='grade'>{$m->letter_grade}</td>
                <td>{$this->fmt($m->grade_point)}</td>
                <td>{$this->fmt($m->highest_marks)}</td>
                <td>{$promoGrade}</td>
            </tr>";
        }

        $bTotalObtained = $this->fmt($summary?->total_marks_obtained);
        $bGpa           = $this->fmt($summary?->gpa);
        $bGrade         = $summary?->letter_grade ?? '—';
        $bTotalStudents = $summary?->total_students_in_class ?? '—';

        return $this->wrapHtml("
            {$this->schoolHeader()}
            {$this->studentInfoBar($student, $exam)}

            <table class='marks-table'>
                <thead>
                    <tr>
                        <th class='subject'>Subject</th>
                        <th>CT</th><th>Att</th>
                        <th>CQ</th><th>CQ CON</th>
                        <th>MCQ</th><th>MCQ CON</th>
                        <th>Total</th><th>Grade</th><th>GP</th>
                        <th>Highest</th><th>Promotion<br>Grade</th>
                    </tr>
                </thead>
                <tbody>{$subjectRows}</tbody>
            </table>

            <div class='summary-row'>
                <div class='summary-block'>
                    <span class='label'>Total</span>
                    <span class='value'>{$bTotalObtained}</span>
                </div>
                <div class='summary-block'>
                    <span class='label'>GPA</span>
                    <span class='value'>{$bGpa}</span>
                </div>
                <div class='summary-block'>
                    <span class='label'>Grade</span>
                    <span class='value'>{$bGrade}</span>
                </div>
                <div class='summary-block'>
                    <span class='label'>Total Students</span>
                    <span class='value'>{$bTotalStudents}</span>
                </div>
            </div>

            {$this->gradeTable()}
            {$this->signatureBlock()}
        ");
    }

    // ─── Tabulation sheets ────────────────────────────────────────────────────

    private function buildTabulationA($students, ExamDefinition $exam): string
    {
        $allMarks = TermMark::query()
            ->where('exam_id', $exam->id)
            ->with('subject')
            ->get()
            ->groupBy('student_id');

        $subjects = TermMark::query()
            ->where('exam_id', $exam->id)
            ->with('subject')
            ->get()
            ->unique('subject_id')
            ->sortBy('subject_id')
            ->values();

        $subjectHeaders = $subjects->map(fn ($m) => "<th>{$m->subject->name}</th>")->implode('');
        $rows = '';
        foreach ($students as $student) {
            $studentMarks = ($allMarks[$student->id] ?? collect())->keyBy('subject_id');
            $cells = $subjects->map(fn ($m) => '<td>' . $this->fmt($studentMarks->get($m->subject_id)?->total_obtained) . '</td>')->implode('');
            $summary = ResultSummary::query()->where('exam_id', $exam->id)->where('student_id', $student->id)->first();
            $tTotal    = $this->fmt($summary?->total_marks_obtained);
            $tGpa      = $this->fmt($summary?->gpa);
            $tGrade    = $summary?->letter_grade  ?? '—';
            $tPos      = $summary?->class_position ?? '—';
            $rows .= "<tr>
                <td>{$student->roll_number}</td>
                <td class='name'>{$student->name}</td>
                {$cells}
                <td><strong>{$tTotal}</strong></td>
                <td>{$tGpa}</td>
                <td>{$tGrade}</td>
                <td>{$tPos}</td>
            </tr>";
        }

        return $this->wrapHtml("
            {$this->schoolHeader()}
            <h2 style='text-align:center;font-size:13pt;margin:6px 0'>
                Tabulation Sheet — Class {$exam->class_name} {$exam->section} — {$exam->title}
            </h2>
            <table class='marks-table tabulation'>
                <thead>
                    <tr>
                        <th>Roll</th><th class='name'>Name</th>
                        {$subjectHeaders}
                        <th>Total</th><th>GPA</th><th>Grade</th><th>Pos</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
        ");
    }

    private function buildTabulationB($students, ExamDefinition $exam): string
    {
        $allMarks = PretestMark::query()
            ->where('exam_id', $exam->id)
            ->with('subject')
            ->get()
            ->groupBy('student_id');

        $subjects = PretestMark::query()
            ->where('exam_id', $exam->id)
            ->with('subject')
            ->get()
            ->unique('subject_id')
            ->sortBy('subject_id')
            ->values();

        $subjectHeaders = $subjects->map(fn ($m) => "<th>{$m->subject->name}</th>")->implode('');
        $rows = '';
        foreach ($students as $student) {
            $studentMarks = ($allMarks[$student->id] ?? collect())->keyBy('subject_id');
            $cells = $subjects->map(fn ($m) => '<td>' . $this->fmt($studentMarks->get($m->subject_id)?->total_obtained) . '</td>')->implode('');
            $summary = ResultSummary::query()->where('exam_id', $exam->id)->where('student_id', $student->id)->first();
            $bTabTotal = $this->fmt($summary?->total_marks_obtained);
            $bTabGpa   = $this->fmt($summary?->gpa);
            $bTabGrade = $summary?->letter_grade ?? '—';
            $rows .= "<tr>
                <td>{$student->roll_number}</td>
                <td class='name'>{$student->name}</td>
                {$cells}
                <td><strong>{$bTabTotal}</strong></td>
                <td>{$bTabGpa}</td>
                <td>{$bTabGrade}</td>
            </tr>";
        }

        return $this->wrapHtml("
            {$this->schoolHeader()}
            <h2 style='text-align:center;font-size:13pt;margin:6px 0'>
                Tabulation Sheet — Class {$exam->class_name} {$exam->section} — {$exam->title}
            </h2>
            <table class='marks-table tabulation'>
                <thead>
                    <tr>
                        <th>Roll</th><th class='name'>Name</th>
                        {$subjectHeaders}
                        <th>Total</th><th>GPA</th><th>Grade</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
        ");
    }

    // ─── Shared HTML helpers ──────────────────────────────────────────────────

    private function schoolHeader(): string
    {
        return "
        <div class='school-header'>
            <h1>Cantonment Public School &amp; College, Saidpur</h1>
            <p class='sub'>Student Report Card</p>
        </div>";
    }

    private function studentInfoBar(Student $student, ExamDefinition $exam): string
    {
        $stream = $student->stream?->name ?? '—';
        return "
        <table class='info-bar'>
            <tr>
                <td><b>Name:</b> {$student->name}</td>
                <td><b>ID:</b> {$student->student_code}</td>
                <td><b>Roll:</b> {$student->roll_number}</td>
                <td><b>Class:</b> {$student->class_name}</td>
                <td><b>Section:</b> {$student->section}</td>
                <td><b>Stream:</b> {$stream}</td>
                <td><b>Exam:</b> {$exam->title}</td>
            </tr>
        </table>";
    }

    private function gradeTable(): string
    {
        return "
        <div class='grade-ref'>
            <table>
                <tr><th>Marks %</th><th>Grade</th><th>GP</th></tr>
                <tr><td>80–100</td><td>A+</td><td>5.00</td></tr>
                <tr><td>70–79</td><td>A</td><td>4.00</td></tr>
                <tr><td>60–69</td><td>A-</td><td>3.50</td></tr>
                <tr><td>50–59</td><td>B</td><td>3.00</td></tr>
                <tr><td>40–49</td><td>C</td><td>2.00</td></tr>
                <tr><td>33–39</td><td>D</td><td>1.00</td></tr>
                <tr><td>0–32</td><td>F</td><td>0.00</td></tr>
            </table>
        </div>";
    }

    private function signatureBlock(): string
    {
        return "
        <div class='signatures'>
            <div class='sig'>Class Teacher</div>
            <div class='sig'>Principal</div>
            <div class='sig'>Guardian</div>
        </div>";
    }

    private function fmt(?float $value): string
    {
        if ($value === null) {
            return '—';
        }
        // Show as integer if whole number, else 2 decimal places
        return $value == (int) $value ? (string) (int) $value : number_format($value, 2);
    }

    private function wrapHtml(string $body): string
    {
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'><style>
            body { font-family: Arial, sans-serif; font-size: 9pt; color: #111; }
            .school-header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 6px; margin-bottom: 8px; }
            .school-header h1 { font-size: 14pt; margin: 0 0 2px; }
            .school-header .sub { font-size: 9pt; margin: 0; }
            .info-bar { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 8.5pt; }
            .info-bar td { padding: 3px 6px; border: 1px solid #ccc; }
            .marks-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 8pt; }
            .marks-table th { background: #2c3e6b; color: #fff; padding: 4px 3px; text-align: center; border: 1px solid #fff; font-size: 7.5pt; }
            .marks-table td { padding: 3px 3px; text-align: center; border: 1px solid #ccc; }
            .marks-table td.subject { text-align: left; padding-left: 5px; }
            .marks-table td.total { font-weight: bold; }
            .marks-table td.grade { font-weight: bold; color: #2c3e6b; }
            .marks-table tr:nth-child(even) { background: #f7f7f7; }
            .tabulation th, .tabulation td { font-size: 7pt; padding: 2px; }
            .tabulation td.name { text-align: left; }
            .summary-row { display: flex; gap: 16px; margin: 10px 0; }
            .summary-block { background: #f0f4ff; border: 1px solid #ccd; padding: 6px 12px; border-radius: 4px; }
            .summary-block .label { font-size: 7.5pt; color: #666; display: block; }
            .summary-block .value { font-size: 12pt; font-weight: bold; color: #2c3e6b; }
            .bottom-row { display: flex; gap: 20px; font-size: 8.5pt; margin: 8px 0; padding: 6px; background: #f9f9f9; border: 1px solid #ddd; }
            .bottom-row .label { color: #555; }
            .grade-ref { float: right; margin-top: -60px; }
            .grade-ref table { border-collapse: collapse; font-size: 7.5pt; }
            .grade-ref td, .grade-ref th { border: 1px solid #ccc; padding: 2px 5px; text-align: center; }
            .grade-ref th { background: #2c3e6b; color: #fff; }
            .signatures { display: flex; gap: 40px; margin-top: 40px; }
            .sig { width: 160px; border-top: 1px solid #333; text-align: center; font-size: 8pt; padding-top: 4px; }
        </style></head><body>{$body}</body></html>";
    }

    private function renderPdf(string $html, string $orientation = 'P'): string
    {
        $mpdf = new Mpdf([
            'orientation'  => $orientation,
            'margin_top'   => 10,
            'margin_right' => 8,
            'margin_bottom'=> 10,
            'margin_left'  => 8,
            'default_font_size' => 9,
            'default_font'      => 'Arial',
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S'); // Return as string
    }
}
