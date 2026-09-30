<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SupabaseClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KycController extends Controller
{
    public function status(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];
        $profile = $sb->restOne('profiles', ['id' => 'eq.' . $uid]);

        $tier = (int) ($profile['kyc_level'] ?? 0);
        $limits = [
            0 => ['daily' => 0,       'requirements' => ['name','dob','bvn']],
            1 => ['daily' => 50000,   'requirements' => ['nin','address','selfie']],
            2 => ['daily' => 200000,  'requirements' => ['id_card','utility']],
            3 => ['daily' => 5000000, 'requirements' => []],
        ];

        return response()->json([
            'tier'           => $tier,
            'status'         => $profile['kyc_status'] ?? 'not_started',
            'daily_limit'    => $limits[$tier]['daily'] ?? 0,
            'next_step'      => $limits[min($tier + 1, 3)]['requirements'] ?? [],
            'rejection_reason' => $profile['kyc_rejection_reason'] ?? null,
        ]);
    }

    public function submit(Request $request, SupabaseClient $sb)
    {
        $uid = $request->attributes->get('supabase_user')['id'];

        $data = $request->validate([
            'full_name'     => 'required|string|min:3|max:120',
            'date_of_birth' => 'required|date',
            'gender'        => 'nullable|string|in:male,female,other',
            'address'       => 'required|string|min:5|max:255',
            'bvn'           => 'required|digits:11',
            'nin'           => 'required|digits:11',
        ]);

        // Age check — 18+
        $dob = new \DateTime($data['date_of_birth']);
        $age = (new \DateTime())->diff($dob)->y;
        if ($age < 18) {
            return response()->json(['message' => 'You must be at least 18 years old.'], 422);
        }

        // Duplicate BVN/NIN check
        $bvnTaken = $sb->restOne('profiles', ['bvn' => 'eq.' . $data['bvn']]);
        if ($bvnTaken && $bvnTaken['id'] !== $uid) {
            return response()->json(['message' => 'This BVN is already registered.'], 422);
        }
        $ninTaken = $sb->restOne('profiles', ['nin' => 'eq.' . $data['nin']]);
        if ($ninTaken && $ninTaken['id'] !== $uid) {
            return response()->json(['message' => 'This NIN is already registered.'], 422);
        }

        // Save submission
        $rows = $sb->insert('kyc_submissions', [
            'user_id'       => $uid,
            'full_name'     => $data['full_name'],
            'date_of_birth' => $data['date_of_birth'],
            'gender'        => $data['gender'] ?? null,
            'address'       => $data['address'],
            'bvn'           => $data['bvn'],
            'nin'           => $data['nin'],
            'status'        => 'pending',
        ]);

        $submission = $rows[0] ?? null;

        // Update profile status
        $sb->update('profiles', ['id' => $uid], [
            'kyc_status'       => 'pending',
            'kyc_submitted_at' => now()->toIso8601String(),
            'kyc_rejection_reason' => null,
        ]);

        // Audit log
        $sb->insert('kyc_audit_log', [
            'user_id'       => $uid,
            'submission_id' => $submission['id'] ?? null,
            'action'        => 'submitted',
            'notes'         => 'KYC submitted for manual review',
        ]);

        return response()->json([
            'message'    => 'KYC submitted. Review usually takes 24 hours.',
            'submission' => $submission,
        ]);
    }

    public function uploadSelfie(Request $request, SupabaseClient $sb)
    {
        return $this->uploadDoc($request, $sb, 'selfie');
    }

    public function uploadId(Request $request, SupabaseClient $sb)
    {
        return $this->uploadDoc($request, $sb, 'id_card');
    }

    protected function uploadDoc(Request $request, SupabaseClient $sb, string $type)
    {
        $request->validate(['file' => 'required|image|mimes:jpg,jpeg,png|max:5120']);
        $uid = $request->attributes->get('supabase_user')['id'];
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $path = "{$uid}/{$type}_" . Str::random(8) . ".{$ext}";

        $sb->storageUpload(
            'kyc-documents',
            $path,
            (string) file_get_contents($file->getRealPath()),
            (string) $file->getMimeType()
        );

        // Update most recent pending submission
        $sub = $sb->restOne('kyc_submissions', [
            'user_id' => 'eq.' . $uid,
            'status'  => 'eq.pending',
            'order'   => 'created_at.desc',
        ]);
        if ($sub) {
            $field = $type === 'selfie' ? 'selfie_url' : 'id_card_url';
            $sb->update('kyc_submissions', ['id' => $sub['id']], [$field => $path]);
        }

        return response()->json(['path' => $path, 'type' => $type]);
    }
}