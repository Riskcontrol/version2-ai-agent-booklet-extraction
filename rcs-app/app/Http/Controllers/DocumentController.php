<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class DocumentController extends Controller
{
    public function upload(Request $req)
    {
        $req->validate([
            'file' => 'required|mimes:pdf|max:102400',
            'session' => 'nullable|string',
            'start_page' => 'nullable|integer|min:1',
            'end_page' => 'nullable|integer|min:1|gte:start_page',
            'api_key_tier' => 'nullable|string|in:GEMINI_API_KEY_FREE_TIER_1,GEMINI_API_KEY_FREE_TIER_2,GEMINI_API_KEY_FREE_TIER_3,GEMINI_API_KEY_FREE_TIER_4,GEMINI_API_KEY_FREE_TIER_5,GEMINI_API_KEY_FREE_TIER_6,GEMINI_API_KEY_FREE_TIER_7,GEMINI_API_KEY_FREE_TIER_8,GEMINI_API_KEY_FREE_TIER_9,GEMINI_API_KEY_FREE_TIER_10,GEMINI_API_KEY_PAID',
        ]);
        $file = $req->file('file');
        $path = $file->store('convocation', 'public');

        $doc = Document::create([
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'session' => $req->input('session'),
            'status' => 'processing'
        ]);

    // Extend expiry to 24h to accommodate long/parallel processing in CI
    $sourceUrl = URL::temporarySignedRoute('documents.download', now()->addHours(24), ['doc' => $doc->id]);

        $pat = config('services.github.pat');
        if (!empty($pat)) {
            $payload = [
                'source_url' => $sourceUrl,
                'original_filename' => $file->getClientOriginalName(),
                'session' => $doc->session,
                'callback_url' => url(route('github.callback', [], false)),
                'result_upload_url' => url(route('github.uploadResults', [], false)),
                'doc_id' => (string)$doc->id,
                'api_key_tier' => $req->input('api_key_tier', 'GEMINI_API_KEY_FREE_TIER_1'),
            ];
            // Forward optional page range to workflow (agent.py runner will read PAGE_START/PAGE_END)
            if ($req->filled('start_page')) {
                $payload['page_start'] = (int)$req->input('start_page');
            }
            if ($req->filled('end_page')) {
                $payload['page_end'] = (int)$req->input('end_page');
            }
            Http::withToken($pat)
                ->post('https://api.github.com/repos/Riskcontrol/version2-ai-agent-booklet-extraction/dispatches', [
                    'event_type' => 'process_pdf',
                    'client_payload' => $payload
                ]);
        }

        return response()->json(['id' => $doc->id, 'status' => 'processing']);
    }

    public function download(Request $req, Document $doc)
    {
        if (!$req->hasValidSignature()) abort(401);
        $full = Storage::disk('public')->path($doc->path);
        if (!file_exists($full)) abort(404);
        return response()->file($full, ['Content-Type' => 'application/pdf']);
    }

    public function downloadOutput(Request $req, Document $doc, string $type)
    {
        if (!$req->hasValidSignature()) abort(401);
        $url = $type === 'csv' ? $doc->csv_url : $doc->xlsx_url;
        if (!$url) abort(404);
        // Convert public URL back to storage path
        $publicPrefix = Storage::disk('public')->url('');
        if (!str_starts_with($url, $publicPrefix)) abort(404);
        $rel = ltrim(substr($url, strlen($publicPrefix)), '/');
        $full = Storage::disk('public')->path($rel);
        if (!file_exists($full)) abort(404);
        $filename = $doc->filename;
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $downloadName = $base . '.' . $type;
        $mime = $type === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        return response()->download($full, $downloadName, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function index()
    {
        $docs = Document::latest()->get();
        // Attach signed download links for CSV/XLSX if present
        $docs->transform(function($d){
            $d->csv_download = $d->csv_url ? URL::temporarySignedRoute('documents.downloadOutput', now()->addHours(12), ['doc' => $d->id, 'type' => 'csv']) : null;
            $d->xlsx_download = $d->xlsx_url ? URL::temporarySignedRoute('documents.downloadOutput', now()->addHours(12), ['doc' => $d->id, 'type' => 'xlsx']) : null;
            return $d;
        });
        return $docs;
    }

    public function delete(Request $req, Document $doc)
    {
        // Delete associated students
        Student::where('document_id', $doc->id)->delete();
        
        // Delete PDF file from storage
        if ($doc->path) {
            Storage::disk('public')->delete($doc->path);
        }
        
        // Delete CSV/XLSX files if they exist
        if ($doc->csv_url) {
            $publicPrefix = Storage::disk('public')->url('');
            if (str_starts_with($doc->csv_url, $publicPrefix)) {
                $rel = ltrim(substr($doc->csv_url, strlen($publicPrefix)), '/');
                Storage::disk('public')->delete($rel);
            }
        }
        if ($doc->xlsx_url) {
            $publicPrefix = Storage::disk('public')->url('');
            if (str_starts_with($doc->xlsx_url, $publicPrefix)) {
                $rel = ltrim(substr($doc->xlsx_url, strlen($publicPrefix)), '/');
                Storage::disk('public')->delete($rel);
            }
        }
        
        // Delete document record
        $doc->delete();
        
        return response()->json(['deleted' => true]);
    }
}
