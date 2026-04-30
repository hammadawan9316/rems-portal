<?php

namespace App\Controllers\Api;

use App\Models\BusinessProfileModel;

class BusinessProfileController extends BaseApiController
{
    public function index()
    {
        $model = new BusinessProfileModel();
        $params = $this->getListQueryParams();

        $builder = $model->builder()->select('*');
        if ($params['search'] !== '') {
            $builder->groupStart()
                ->like('company_name', $params['search'])
                ->orLike('admin_name', $params['search'])
                ->orLike('email', $params['search'])
                ->orLike('phone', $params['search'])
                ->orLike('website_url', $params['search'])
                ->groupEnd();
        }

        $total = (int) $builder->countAllResults(false);
        $items = $builder
            ->orderBy('is_active', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($params['perPage'], $params['offset'])
            ->get()
            ->getResultArray();

        return $this->res->paginated($items, $total, $params['page'], $params['perPage'], 'Business profiles retrieved successfully');
    }

    public function show(int $id)
    {
        $model = new BusinessProfileModel();
        $profile = $model->find($id);

        if (!is_array($profile)) {
            return $this->res->notFound('Business profile not found.');
        }

        return $this->res->ok($profile, 'Business profile retrieved successfully.');
    }

    public function store()
    {
        $model = new BusinessProfileModel();
        $data = $this->getRequestData(false);
        $errors = $this->validatePayload($data);
        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        $payload = $this->buildPayload($data);
        $model->insert($payload);
        $id = (int) $model->getInsertID();

        if ((bool) ($payload['is_active'] ?? false)) {
            $this->deactivateOtherProfiles($model, $id);
        }

        $created = $model->find($id);

        return $this->res->created($created, 'Business profile created successfully.');
    }

    public function update(int $id)
    {
        $model = new BusinessProfileModel();
        $existing = $model->find($id);
        if (!is_array($existing)) {
            return $this->res->notFound('Business profile not found.');
        }

        $data = $this->getRequestData(false);
        if ($data === []) {
            return $this->res->badRequest('No business profile fields supplied to update.');
        }

        $errors = $this->validatePayload($data, true);
        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        $payload = $this->buildPayload($data, true);
        if ($payload === []) {
            return $this->res->badRequest('No valid business profile fields supplied to update.');
        }

        $model->update($id, $payload);

        if (array_key_exists('is_active', $payload) && (bool) $payload['is_active']) {
            $this->deactivateOtherProfiles($model, $id);
        }

        $updated = $model->find($id);

        return $this->res->ok($updated, 'Business profile updated successfully.');
    }

    public function delete(int $id)
    {
        $model = new BusinessProfileModel();
        $existing = $model->find($id);
        if (!is_array($existing)) {
            return $this->res->notFound('Business profile not found.');
        }

        $wasActive = (bool) ($existing['is_active'] ?? false);
        $model->delete($id);

        if ($wasActive) {
            $fallback = $model->orderBy('id', 'DESC')->first();
            if (is_array($fallback)) {
                $fallbackId = (int) ($fallback['id'] ?? 0);
                if ($fallbackId > 0) {
                    $model->update($fallbackId, ['is_active' => 1]);
                    $this->deactivateOtherProfiles($model, $fallbackId);
                }
            }
        }

        return $this->res->ok(null, 'Business profile deleted successfully.');
    }

    public function toggleActive(int $id)
    {
        $model = new BusinessProfileModel();
        $existing = $model->find($id);
        if (!is_array($existing)) {
            return $this->res->notFound('Business profile not found.');
        }

        $isCurrentlyActive = (bool) ($existing['is_active'] ?? false);

        if ($isCurrentlyActive) {
            $fallback = $model->where('id !=', $id)
                ->orderBy('updated_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->first();

            if (!is_array($fallback)) {
                return $this->res->badRequest('At least one business profile must remain active.');
            }

            $fallbackId = (int) ($fallback['id'] ?? 0);
            if ($fallbackId <= 0) {
                return $this->res->badRequest('At least one business profile must remain active.');
            }

            $model->update($id, ['is_active' => 0]);
            $model->update($fallbackId, ['is_active' => 1]);
            $this->deactivateOtherProfiles($model, $fallbackId);

            $updated = $model->find($fallbackId);

            return $this->res->ok($updated, 'Business profile active status updated successfully.');
        }

        $model->update($id, ['is_active' => 1]);
        $this->deactivateOtherProfiles($model, $id);

        $updated = $model->find($id);

        return $this->res->ok($updated, 'Business profile active status updated successfully.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validatePayload(array $data, bool $isPartial = false): array
    {
        $errors = [];

        if (!$isPartial || array_key_exists('company_name', $data)) {
            $companyName = trim((string) ($data['company_name'] ?? ($data['companyName'] ?? '')));
            if ($companyName === '') {
                $errors['company_name'] = 'Company name is required.';
            }
        }

        if (!$isPartial || array_key_exists('admin_name', $data) || array_key_exists('adminName', $data)) {
            $adminName = trim((string) ($data['admin_name'] ?? ($data['adminName'] ?? '')));
            if ($adminName === '') {
                $errors['admin_name'] = 'Admin name is required.';
            }
        }

        if (!$isPartial || array_key_exists('email', $data)) {
            $email = trim((string) ($data['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'A valid email is required.';
            }
        }

        if (!$isPartial || array_key_exists('phone', $data)) {
            $phone = trim((string) ($data['phone'] ?? ''));
            if ($phone === '') {
                $errors['phone'] = 'Phone is required.';
            }
        }

        if (!$isPartial || array_key_exists('address', $data)) {
            $address = trim((string) ($data['address'] ?? ''));
            if ($address === '') {
                $errors['address'] = 'Address is required.';
            }
        }

        if (!$isPartial || array_key_exists('website_url', $data) || array_key_exists('websiteUrl', $data)) {
            $website = trim((string) ($data['website_url'] ?? ($data['websiteUrl'] ?? '')));
            if ($website === '') {
                $errors['website_url'] = 'Website URL is required.';
            }
        }

        $followupDaysProvided = array_key_exists('followup_notification_days', $data)
            || array_key_exists('follow_up_notification_days', $data)
            || array_key_exists('followupNotificationDays', $data)
            || array_key_exists('followupDays', $data);

        if (!$isPartial || $followupDaysProvided) {
            $followupDays = $this->normalizeFollowupDays($data['followup_notification_days'] ?? ($data['follow_up_notification_days'] ?? ($data['followupNotificationDays'] ?? ($data['followupDays'] ?? null))));
            if ($followupDays === null) {
                $errors['followup_notification_days'] = 'Follow-up notification days must be a valid non-negative integer.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildPayload(array $data, bool $isPartial = false): array
    {
        $payload = [];

        if (!$isPartial || array_key_exists('company_name', $data) || array_key_exists('companyName', $data)) {
            $payload['company_name'] = trim((string) ($data['company_name'] ?? ($data['companyName'] ?? '')));
        }

        if (!$isPartial || array_key_exists('admin_name', $data) || array_key_exists('adminName', $data)) {
            $payload['admin_name'] = trim((string) ($data['admin_name'] ?? ($data['adminName'] ?? '')));
        }

        if (!$isPartial || array_key_exists('email', $data)) {
            $payload['email'] = trim((string) ($data['email'] ?? ''));
        }

        if (!$isPartial || array_key_exists('phone', $data)) {
            $payload['phone'] = trim((string) ($data['phone'] ?? ''));
        }

        if (!$isPartial || array_key_exists('address', $data)) {
            $payload['address'] = trim((string) ($data['address'] ?? ''));
        }

        if (!$isPartial || array_key_exists('website_url', $data) || array_key_exists('websiteUrl', $data)) {
            $payload['website_url'] = trim((string) ($data['website_url'] ?? ($data['websiteUrl'] ?? '')));
        }

        if (!$isPartial || array_key_exists('followup_notification_days', $data) || array_key_exists('follow_up_notification_days', $data) || array_key_exists('followupNotificationDays', $data) || array_key_exists('followupDays', $data)) {
            $followupDays = $this->normalizeFollowupDays($data['followup_notification_days'] ?? ($data['follow_up_notification_days'] ?? ($data['followupNotificationDays'] ?? ($data['followupDays'] ?? null))));
            if ($followupDays !== null) {
                $payload['followup_notification_days'] = $followupDays;
            }
        }

        if (!$isPartial || array_key_exists('followup_notification_text', $data) || array_key_exists('follow_up_notification_text', $data) || array_key_exists('followupNotificationText', $data) || array_key_exists('followupText', $data) || array_key_exists('message', $data)) {
            $payload['followup_notification_text'] = $this->normalizeFollowupText($data['followup_notification_text'] ?? ($data['follow_up_notification_text'] ?? ($data['followupNotificationText'] ?? ($data['followupText'] ?? ($data['message'] ?? null)))));
        }

        if (!$isPartial || array_key_exists('is_active', $data) || array_key_exists('active', $data)) {
            $payload['is_active'] = $this->normalizeBool($data['is_active'] ?? ($data['active'] ?? true));
        }

        return $payload;
    }

    private function deactivateOtherProfiles(BusinessProfileModel $model, int $activeId): void
    {
        $model->builder()
            ->set('is_active', 0)
            ->where('id !=', $activeId)
            ->update();
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeFollowupDays(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $days = (int) $value;

        return $days < 0 ? null : $days;
    }

    private function normalizeFollowupText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
