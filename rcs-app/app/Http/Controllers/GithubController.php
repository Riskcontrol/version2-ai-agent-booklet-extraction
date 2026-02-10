<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GithubController extends Controller
{
    public function callback(Request $req)
    {
        $sig = $req->header('X-Extractor-Signature');
        $secret = (string) config('services.extractor.secret');
        $body = $req->getContent();
        $expected = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, (string)$sig)) {
            Log::warning('extractor callback unauthorized', [
                'has_sig' => !empty($sig),
                'sig_len' => is_string($sig) ? strlen($sig) : 0,
                'secret_set' => $secret !== '',
                'body_len' => is_string($body) ? strlen($body) : 0,
                'ip' => $req->ip(),
                'ua' => substr((string) $req->userAgent(), 0, 120),
            ]);
            abort(401);
        }

        $payload = $req->json()->all();
        $doc = Document::where('filename', $payload['filename'] ?? '')->latest()->first();
        if (!$doc) return response()->noContent();

        // Do not overwrite URLs from the upload-results step with runner-local paths like "outputs/*.csv".
        // Only mark status complete here and (optionally) set URLs if they are absolute http(s) links and current fields are empty.
        $doc->status = 'complete';
        $files = $payload['files'] ?? [];
        $csv = $files['csv'] ?? null;
        $xlsx = $files['xlsx'] ?? null;
        if (!$doc->csv_url && is_string($csv) && preg_match('/^https?:\/\//i', $csv)) {
            $doc->csv_url = $csv;
        }
        if (!$doc->xlsx_url && is_string($xlsx) && preg_match('/^https?:\/\//i', $xlsx)) {
            $doc->xlsx_url = $xlsx;
        }
        $doc->save();

        if (!empty($payload['rows']) && is_array($payload['rows'])) {
            foreach ($payload['rows'] as $r) {
                Student::create([
                    'document_id' => $doc->id,
                    'surname' => $r['surname'] ?? '',
                    'first_name' => $r['first_name'] ?? '',
                    'other_name' => $r['other_name'] ?? '',
                    'course_studied' => $r['course_studied'] ?? null,
                    'faculty' => $r['faculty'] ?? null,
                    'grade' => $r['grade'] ?? null,
                    'qualification_obtained' => $r['qualification_obtained'] ?? null,
                    'session' => $r['session'] ?? null,
                ]);
            }
        }
        return response()->json(['ok' => true]);
    }

    public function uploadResults(Request $req)
    {
        // Some server/proxy setups do not forward the Authorization header to PHP.
        // Accept either a Bearer token OR an explicit header.
        $auth = $req->bearerToken() ?: $req->header('X-Extractor-Token');
        $expectedToken = (string) config('services.extractor.token');
        if ((string)$auth !== $expectedToken) {
            Log::warning('extractor upload-results unauthorized', [
                'has_bearer' => !empty($req->bearerToken()),
                'has_x_token' => !empty($req->header('X-Extractor-Token')),
                'expected_set' => $expectedToken !== '',
                'ip' => $req->ip(),
                'ua' => substr((string) $req->userAgent(), 0, 120),
            ]);
            abort(401);
        }

        $docId = $req->input('doc_id');
        $doc = Document::find($docId);
        if (!$doc) abort(404);

        $csvFile = $req->file('csv');
        $xlsxFile = $req->file('xlsx');
        $docxFile = $req->file('docx');

        if ($csvFile) {
            $csvPath = $csvFile->store('processed', 'public');
            $doc->csv_url = Storage::disk('public')->url($csvPath);

            if (($h = fopen(Storage::disk('public')->path($csvPath), 'r')) !== false) {
                $header = fgetcsv($h);
                while (($row = fgetcsv($h)) !== false) {
                    $data = array_combine($header, $row);
                    Student::create([
                        'document_id' => $doc->id,
                        'surname' => $data['surname'] ?? '',
                        'first_name' => $data['first_name'] ?? '',
                        'other_name' => $data['other_name'] ?? '',
                        'course_studied' => $data['course_studied'] ?? null,
                        'faculty' => $data['faculty'] ?? null,
                        'grade' => $data['grade'] ?? null,
                        'qualification_obtained' => $data['qualification_obtained'] ?? null,
                        'session' => $data['session'] ?? $doc->session,
                    ]);
                }
                fclose($h);
            }
        }
        if ($xlsxFile) { $xlsxPath = $xlsxFile->store('processed', 'public'); $doc->xlsx_url = Storage::disk('public')->url($xlsxPath); }
        if ($docxFile) { $docxPath = $docxFile->store('processed', 'public'); $doc->docx_url = Storage::disk('public')->url($docxPath); }

        $doc->status = 'complete';
        $doc->save();
        return response()->json(['ok' => true, 'doc' => $doc]);
    }
}
