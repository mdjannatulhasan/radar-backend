<?php

namespace App\Services\Pps;

use App\Models\Pps\ExamDefinition;
use App\Models\Pps\PretestMark;
use App\Models\Pps\ResultSummary;
use App\Models\Pps\TermMark;
use App\Models\Student;
use Mpdf\Mpdf;

class ReportCardService
{
    /**
     * Generate a report card PDF for one student.
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

        return $this->renderPdf($html, 'L');
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
            || str_contains(strtolower($exam->title ?? ''), '2nd')
            || str_contains(strtolower($exam->assessment_type ?? ''), 'annual');

        // Build subject rows
        $subjectRows = '';
        foreach ($marks as $i => $m) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f5f7fb';
            $subjectName = htmlspecialchars($m->subject->name ?? '');

            if ($isSecondTerm) {
                $vtRaw = $this->fmt($m->vt);
                $vtCon = $this->fmt($m->vt_con);
                $vtCells = "<td style='background:{$bg}'>{$vtRaw}</td><td style='background:{$bg}'>{$vtCon}</td>";
            } else {
                $vtCells = "<td style='background:{$bg}'>—</td><td style='background:{$bg}'>—</td>";
            }

            $gradeColor = $this->gradeColor($m->letter_grade);

            $subjectRows .= "
            <tr>
                <td style='text-align:left;padding-left:6px;background:{$bg}'>{$subjectName}</td>
                <td style='background:{$bg}'>{$this->fmt($m->spot_test)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->spot_test_con)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->class_test2)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->class_test2_con)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->attendance)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->term_marks)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->term_con)}</td>
                {$vtCells}
                <td style='font-weight:bold;background:{$bg}'>{$this->fmt($m->total_obtained)}</td>
                <td style='font-weight:bold;color:{$gradeColor};background:{$bg}'>{$m->letter_grade}</td>
                <td style='background:{$bg}'>{$this->fmt($m->highest_marks)}</td>
            </tr>";
        }

        $promotionText = $summary?->is_promoted === true ? 'Promoted' : ($summary?->is_promoted === false ? 'Not Promoted' : '—');
        $promotionColor = $summary?->is_promoted === true ? '#166534' : ($summary?->is_promoted === false ? '#991b1b' : '#555');
        $totalObtained  = $this->fmt($summary?->total_marks_obtained);
        $totalFull      = $this->fmt($summary?->total_marks_full);
        $gpa            = $this->fmt($summary?->gpa);
        $letterGrade    = $summary?->letter_grade     ?? '—';
        $classPos       = $summary?->class_position   ?? '—';
        $totalStudents  = $summary?->total_students_in_class ?? '—';
        $discipline     = $summary?->discipline       ?? '—';
        $handwriting    = $summary?->handwriting      ?? '—';
        $workingDays    = $summary?->total_working_days ?? '—';
        $presence       = $summary?->total_presence   ?? '—';
        $gpaColor       = $this->gpaColor((float)($summary?->gpa ?? 0));
        $stream         = htmlspecialchars($student->stream?->name ?? '—');
        $examYear       = $exam->exam_date?->format('Y') ?? date('Y');
        $examTitle      = htmlspecialchars($exam->title ?? '');

        $vtHeaderSpan = $isSecondTerm
            ? '<th colspan="2" style="background:#1a3a5c">2nd Term Exam</th>'
            : '<th colspan="2" style="background:#1a3a5c;opacity:0.6">2nd Term</th>';

        return $this->wrapHtml("
        " . $this->pageHeader($examYear, $examTitle) . "
        " . $this->studentInfoTable($student, $exam, $stream) . "

        <table class='marks-table'>
            <thead>
                <tr>
                    <th rowspan='2' class='subj-head'>Subjects</th>
                    <th colspan='8' style='background:#1a3a5c'>First Term Exam</th>
                    {$vtHeaderSpan}
                    <th rowspan='2' class='total-head'>Total<br>Marks</th>
                    <th rowspan='2' class='grade-head'>Grade</th>
                    <th rowspan='2' class='high-head'>Highest<br>Marks</th>
                </tr>
                <tr>
                    <th>Spot<br>Test</th>
                    <th>ST<br>Con</th>
                    <th>CT-2</th>
                    <th>CT-2<br>Con</th>
                    <th>Att</th>
                    <th>Term<br>Marks</th>
                    <th>Term<br>Con</th>
                    <th>Grade</th>
                    <th>VT</th>
                    <th>VT<br>Con</th>
                </tr>
            </thead>
            <tbody>{$subjectRows}</tbody>
        </table>

        <table style='width:100%;border-collapse:collapse;margin-top:8px;font-size:8.5pt'>
            <tr>
                <td style='width:50%;vertical-align:top;padding-right:8px'>
                    <table style='width:100%;border-collapse:collapse;border:1px solid #ccc'>
                        <tr style='background:#e8edf5'>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Total Students in Class</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$totalStudents}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Working Days</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$workingDays}</td>
                        </tr>
                        <tr>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Total Marks</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$totalObtained} / {$totalFull}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Total Presence</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$presence}</td>
                        </tr>
                        <tr style='background:#e8edf5'>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Discipline</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$discipline}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Hand Writing</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$handwriting}</td>
                        </tr>
                        <tr>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>GPA</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center;font-weight:bold;color:{$gpaColor}'>{$gpa}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Grade</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center;font-weight:bold'>{$letterGrade}</td>
                        </tr>
                        <tr style='background:#e8edf5'>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Position</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$classPos} / {$totalStudents}</td>
                            <td style='padding:6px 8px;border:1px solid #ccc;font-weight:bold;font-size:9pt'>Result</td>
                            <td style='padding:6px 8px;border:1px solid #ccc;text-align:center;font-weight:bold;color:{$promotionColor};font-size:9pt'>{$promotionText}</td>
                        </tr>
                    </table>
                </td>
                <td style='width:50%;vertical-align:top'>
                    " . $this->gradeTable() . "
                </td>
            </tr>
        </table>

        " . $this->signatureBlock() . "
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
        foreach ($marks as $i => $m) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f5f7fb';
            $subjectName = htmlspecialchars($m->subject->name ?? '');
            $promoGrade  = $m->promotion_grade ?? '—';
            $gradeColor  = $this->gradeColor($m->letter_grade);

            $subjectRows .= "
            <tr>
                <td style='text-align:left;padding-left:6px;background:{$bg}'>{$subjectName}</td>
                <td style='background:{$bg}'>{$this->fmt($m->ct)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->attendance)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->cq)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->cq_con)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->mcq)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->mcq_con)}</td>
                <td style='font-weight:bold;background:{$bg}'>{$this->fmt($m->total_obtained)}</td>
                <td style='font-weight:bold;color:{$gradeColor};background:{$bg}'>{$m->letter_grade}</td>
                <td style='background:{$bg}'>{$this->fmt($m->grade_point)}</td>
                <td style='background:{$bg}'>{$this->fmt($m->highest_marks)}</td>
                <td style='background:{$bg}'>{$promoGrade}</td>
            </tr>";
        }

        $bTotal    = $this->fmt($summary?->total_marks_obtained);
        $bGpa      = $this->fmt($summary?->gpa);
        $bGrade    = $summary?->letter_grade ?? '—';
        $bStudents = $summary?->total_students_in_class ?? '—';
        $bPos      = $summary?->class_position ?? '—';
        $promotionText  = $summary?->is_promoted === true ? 'Promoted' : ($summary?->is_promoted === false ? 'Not Promoted' : '—');
        $promotionColor = $summary?->is_promoted === true ? '#166534' : ($summary?->is_promoted === false ? '#991b1b' : '#555');
        $gpaColor       = $this->gpaColor((float)($summary?->gpa ?? 0));
        $stream         = htmlspecialchars($student->stream?->name ?? '—');
        $examYear       = $exam->exam_date?->format('Y') ?? date('Y');
        $examTitle      = htmlspecialchars($exam->title ?? '');

        return $this->wrapHtml("
        " . $this->pageHeader($examYear, $examTitle) . "
        " . $this->studentInfoTable($student, $exam, $stream) . "

        <table class='marks-table'>
            <thead>
                <tr>
                    <th class='subj-head'>Subject</th>
                    <th>CT</th>
                    <th>Att</th>
                    <th>CQ</th>
                    <th>CQ<br>Con</th>
                    <th>MCQ</th>
                    <th>MCQ<br>Con</th>
                    <th class='total-head'>Total</th>
                    <th class='grade-head'>Grade</th>
                    <th>GP</th>
                    <th class='high-head'>Highest</th>
                    <th>Promotion<br>Grade</th>
                </tr>
            </thead>
            <tbody>{$subjectRows}</tbody>
        </table>

        <table style='width:100%;border-collapse:collapse;margin-top:8px;font-size:8.5pt'>
            <tr>
                <td style='width:50%;vertical-align:top;padding-right:8px'>
                    <table style='width:100%;border-collapse:collapse;border:1px solid #ccc'>
                        <tr style='background:#e8edf5'>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Total Students</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$bStudents}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Total Marks</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$bTotal}</td>
                        </tr>
                        <tr>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>GPA</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center;font-weight:bold;color:{$gpaColor}'>{$bGpa}</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Grade</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center;font-weight:bold'>{$bGrade}</td>
                        </tr>
                        <tr style='background:#e8edf5'>
                            <td style='padding:4px 8px;border:1px solid #ccc;font-weight:bold'>Position</td>
                            <td style='padding:4px 8px;border:1px solid #ccc;text-align:center'>{$bPos} / {$bStudents}</td>
                            <td style='padding:6px 8px;border:1px solid #ccc;font-weight:bold'>Result</td>
                            <td style='padding:6px 8px;border:1px solid #ccc;text-align:center;font-weight:bold;color:{$promotionColor}'>{$promotionText}</td>
                        </tr>
                    </table>
                </td>
                <td style='width:50%;vertical-align:top'>
                    " . $this->gradeTable() . "
                </td>
            </tr>
        </table>

        " . $this->signatureBlock() . "
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

        $subjectHeaders = $subjects->map(fn ($m) => "<th>" . htmlspecialchars($m->subject->name ?? '') . "</th>")->implode('');

        $rows = '';
        foreach ($students as $i => $student) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f5f7fb';
            $studentMarks = ($allMarks[$student->id] ?? collect())->keyBy('subject_id');
            $cells = $subjects->map(function ($m) use ($studentMarks, $bg) {
                $val = $this->fmt($studentMarks->get($m->subject_id)?->total_obtained);
                return "<td style='background:{$bg}'>{$val}</td>";
            })->implode('');
            $summary  = ResultSummary::query()->where('exam_id', $exam->id)->where('student_id', $student->id)->first();
            $tTotal   = $this->fmt($summary?->total_marks_obtained);
            $tGpa     = $this->fmt($summary?->gpa);
            $tGrade   = $summary?->letter_grade  ?? '—';
            $tPos     = $summary?->class_position ?? '—';
            $gColor   = $this->gradeColor($tGrade);

            $rows .= "<tr>
                <td style='background:{$bg};text-align:center'>{$student->roll_number}</td>
                <td style='background:{$bg};text-align:left;padding-left:4px'>" . htmlspecialchars($student->name) . "</td>
                {$cells}
                <td style='font-weight:bold;background:{$bg}'>{$tTotal}</td>
                <td style='background:{$bg}'>{$tGpa}</td>
                <td style='font-weight:bold;color:{$gColor};background:{$bg}'>{$tGrade}</td>
                <td style='background:{$bg}'>{$tPos}</td>
            </tr>";
        }

        $year = $exam->exam_date?->format('Y') ?? date('Y');

        return $this->wrapHtml("
        <div style='text-align:center;border-bottom:2px solid #1a3a5c;padding-bottom:6px;margin-bottom:8px'>
            <div style='font-size:15pt;font-weight:bold;color:#1a3a5c'>Cantonment Public School &amp; College, Saidpur</div>
            <div style='font-size:11pt;font-weight:bold;margin-top:2px'>Tabulation Sheet — {$year}</div>
            <div style='font-size:9pt;color:#444;margin-top:2px'>Class {$exam->class_name} — Section {$exam->section} — " . htmlspecialchars($exam->title) . "</div>
        </div>
        <table class='marks-table tabulation'>
            <thead>
                <tr>
                    <th style='width:30px'>Roll</th>
                    <th style='text-align:left;min-width:100px'>Name</th>
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

        $subjectHeaders = $subjects->map(fn ($m) => "<th>" . htmlspecialchars($m->subject->name ?? '') . "</th>")->implode('');

        $rows = '';
        foreach ($students as $i => $student) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f5f7fb';
            $studentMarks = ($allMarks[$student->id] ?? collect())->keyBy('subject_id');
            $cells = $subjects->map(function ($m) use ($studentMarks, $bg) {
                $val = $this->fmt($studentMarks->get($m->subject_id)?->total_obtained);
                return "<td style='background:{$bg}'>{$val}</td>";
            })->implode('');
            $summary  = ResultSummary::query()->where('exam_id', $exam->id)->where('student_id', $student->id)->first();
            $bTotal   = $this->fmt($summary?->total_marks_obtained);
            $bGpa     = $this->fmt($summary?->gpa);
            $bGrade   = $summary?->letter_grade ?? '—';
            $gColor   = $this->gradeColor($bGrade);

            $rows .= "<tr>
                <td style='background:{$bg};text-align:center'>{$student->roll_number}</td>
                <td style='background:{$bg};text-align:left;padding-left:4px'>" . htmlspecialchars($student->name) . "</td>
                {$cells}
                <td style='font-weight:bold;background:{$bg}'>{$bTotal}</td>
                <td style='background:{$bg}'>{$bGpa}</td>
                <td style='font-weight:bold;color:{$gColor};background:{$bg}'>{$bGrade}</td>
            </tr>";
        }

        $year = $exam->exam_date?->format('Y') ?? date('Y');

        return $this->wrapHtml("
        <div style='text-align:center;border-bottom:2px solid #1a3a5c;padding-bottom:6px;margin-bottom:8px'>
            <div style='font-size:15pt;font-weight:bold;color:#1a3a5c'>Cantonment Public School &amp; College, Saidpur</div>
            <div style='font-size:11pt;font-weight:bold;margin-top:2px'>Tabulation Sheet — {$year}</div>
            <div style='font-size:9pt;color:#444;margin-top:2px'>Class {$exam->class_name} — Section {$exam->section} — " . htmlspecialchars($exam->title) . "</div>
        </div>
        <table class='marks-table tabulation'>
            <thead>
                <tr>
                    <th style='width:30px'>Roll</th>
                    <th style='text-align:left;min-width:100px'>Name</th>
                    {$subjectHeaders}
                    <th>Total</th><th>GPA</th><th>Grade</th>
                </tr>
            </thead>
            <tbody>{$rows}</tbody>
        </table>
        ");
    }

    // ─── Page layout helpers ──────────────────────────────────────────────────

    private function pageHeader(string $year, string $examTitle): string
    {
        $logoSvg = $this->schoolLogoSvg();

        return "
        <table style='width:100%;border-bottom:2px solid #1a3a5c;margin-bottom:8px;padding-bottom:6px'>
            <tr>
                <td style='width:60px;text-align:center;vertical-align:middle'>{$logoSvg}</td>
                <td style='text-align:center;vertical-align:middle'>
                    <div style='font-size:14pt;font-weight:bold;color:#1a3a5c'>Cantonment Public School &amp; College, Saidpur</div>
                    <div style='font-size:10pt;font-weight:bold;margin-top:3px'>Students Progress Report — {$year}</div>
                    <div style='font-size:8pt;color:#555;margin-top:2px'>{$examTitle}</div>
                </td>
                <td style='width:60px;text-align:center;vertical-align:middle'>{$logoSvg}</td>
            </tr>
        </table>";
    }

    private function studentInfoTable(Student $student, ExamDefinition $exam, string $stream): string
    {
        $name    = htmlspecialchars($student->name ?? '');
        $code    = htmlspecialchars($student->student_code ?? '');
        $roll    = $student->roll_number ?? '—';
        $class   = $student->class_name ?? '—';
        $section = $student->section ?? '—';
        $gender  = ucfirst($student->gender ?? '—');

        return "
        <table style='width:100%;border-collapse:collapse;margin-bottom:8px;font-size:8.5pt;border:1px solid #ccc'>
            <tr style='background:#e8edf5'>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold;width:80px'>Name</td>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold'>{$name}</td>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold;width:80px'>Student ID</td>
                <td style='padding:5px 8px;border:1px solid #ccc'>{$code}</td>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold;width:70px'>Class Roll</td>
                <td style='padding:5px 8px;border:1px solid #ccc'>{$roll}</td>
                <td rowspan='2' style='width:55px;text-align:center;border:1px solid #ccc;vertical-align:middle;color:#aaa;font-size:7pt'>Photo</td>
            </tr>
            <tr>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold'>Class</td>
                <td style='padding:5px 8px;border:1px solid #ccc'>{$class}</td>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold'>Section</td>
                <td style='padding:5px 8px;border:1px solid #ccc'>{$section}</td>
                <td style='padding:5px 8px;border:1px solid #ccc;font-weight:bold'>Gender</td>
                <td style='padding:5px 8px;border:1px solid #ccc'>{$gender}</td>
            </tr>
        </table>";
    }

    private function gradeTable(): string
    {
        return "
        <table style='width:100%;border-collapse:collapse;font-size:8pt'>
            <thead>
                <tr>
                    <th colspan='3' style='background:#1a3a5c;color:#fff;padding:4px;text-align:center'>Grading By Merit</th>
                </tr>
                <tr style='background:#e8edf5'>
                    <th style='padding:3px 6px;border:1px solid #ccc;text-align:center'>Marks %</th>
                    <th style='padding:3px 6px;border:1px solid #ccc;text-align:center'>Grade</th>
                    <th style='padding:3px 6px;border:1px solid #ccc;text-align:center'>Grade Point</th>
                </tr>
            </thead>
            <tbody>
                <tr><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>80–100</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#166534'>A+</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>5.00</td></tr>
                <tr style='background:#f9f9f9'><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>70–79</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#166534'>A</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>4.00</td></tr>
                <tr><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>60–69</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#1d4ed8'>A-</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>3.50</td></tr>
                <tr style='background:#f9f9f9'><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>50–59</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#1d4ed8'>B</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>3.00</td></tr>
                <tr><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>40–49</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#d97706'>C</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>2.00</td></tr>
                <tr style='background:#f9f9f9'><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>33–39</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#d97706'>D</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>1.00</td></tr>
                <tr><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>0–32</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center;font-weight:bold;color:#dc2626'>F</td><td style='padding:3px 6px;border:1px solid #ccc;text-align:center'>0.00</td></tr>
            </tbody>
        </table>";
    }

    private function signatureBlock(): string
    {
        return "
        <table style='width:100%;margin-top:30px;font-size:8pt'>
            <tr>
                <td style='width:33%;text-align:center;padding-top:4px'>
                    <div style='border-top:1px solid #333;padding-top:4px;margin:0 20px'>Class Teacher's Comment &amp; Signature</div>
                </td>
                <td style='width:33%;text-align:center;padding-top:4px'>
                    <div style='border-top:1px solid #333;padding-top:4px;margin:0 20px'>Principal's Signature</div>
                </td>
                <td style='width:33%;text-align:center;padding-top:4px'>
                    <div style='border-top:1px solid #333;padding-top:4px;margin:0 20px'>Guardian's Signature</div>
                </td>
            </tr>
        </table>
        <div style='text-align:center;margin-top:10px;font-size:7pt;color:#888'>
            Login: cpcs.artscolege.com
        </div>";
    }

    private function schoolLogoSvg(): string
    {
        return '<svg width="52" height="52" viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg">
            <circle cx="26" cy="26" r="25" fill="#1a3a5c" stroke="#c9a227" stroke-width="2"/>
            <circle cx="26" cy="26" r="20" fill="none" stroke="#c9a227" stroke-width="1"/>
            <text x="26" y="21" font-family="Arial" font-size="7" font-weight="bold" fill="#ffffff" text-anchor="middle">CPSC</text>
            <text x="26" y="31" font-family="Arial" font-size="5" fill="#c9a227" text-anchor="middle">SAIDPUR</text>
            <text x="26" y="39" font-family="Arial" font-size="4.5" fill="#aaa" text-anchor="middle">EST. 1971</text>
        </svg>';
    }

    // ─── Utility helpers ──────────────────────────────────────────────────────

    private function gradeColor(?string $grade): string
    {
        return match ($grade) {
            'A+', 'A' => '#166534',
            'A-', 'B' => '#1d4ed8',
            'C', 'D'  => '#d97706',
            'F'       => '#dc2626',
            default   => '#111111',
        };
    }

    private function gpaColor(float $gpa): string
    {
        if ($gpa >= 4.5) return '#166534';
        if ($gpa >= 3.5) return '#1d4ed8';
        if ($gpa >= 2.5) return '#d97706';
        return '#dc2626';
    }

    private function fmt(?float $value): string
    {
        if ($value === null) {
            return '—';
        }
        return $value == (int) $value ? (string) (int) $value : number_format($value, 2);
    }

    private function wrapHtml(string $body): string
    {
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'><style>
            body { font-family: Arial, sans-serif; font-size: 9pt; color: #111; margin: 0; padding: 0; }
            .marks-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 8pt; }
            .marks-table th {
                background: #1a3a5c;
                color: #fff;
                padding: 4px 3px;
                text-align: center;
                border: 1px solid #2c4e7c;
                font-size: 7.5pt;
                line-height: 1.2;
            }
            .marks-table td {
                padding: 3px 3px;
                text-align: center;
                border: 1px solid #d0d5e0;
                font-size: 8pt;
            }
            .subj-head  { text-align: left !important; padding-left: 6px !important; min-width: 90px; }
            .total-head { background: #2c3e6b !important; }
            .grade-head { background: #2c3e6b !important; }
            .high-head  { background: #374151 !important; }
            .tabulation th, .tabulation td { font-size: 7pt; padding: 2px 3px; }
        </style></head><body>{$body}</body></html>";
    }

    private function renderPdf(string $html, string $orientation = 'P'): string
    {
        $mpdf = new Mpdf([
            'orientation'       => $orientation,
            'margin_top'        => 8,
            'margin_right'      => 8,
            'margin_bottom'     => 8,
            'margin_left'       => 8,
            'default_font_size' => 9,
            'default_font'      => 'Arial',
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }
}
