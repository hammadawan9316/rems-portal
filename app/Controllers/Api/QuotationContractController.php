<?php

namespace App\Controllers\Api;

use App\Models\ContractModel;
use App\Models\QuotationContractModel;
use App\Models\QuotationModel;

class QuotationContractController extends BaseApiController
{
    public function showByQuotation(int $quotationId)
    {
        $quotation = (new QuotationModel())->find($quotationId);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $quotationContractModel = new QuotationContractModel();
        $assignment = $quotationContractModel->findByQuotationId($quotationId);
        if (!is_array($assignment)) {
            return $this->res->notFound('Quotation contract not found');
        }

        $contract = (new ContractModel())->findDetailed((int) ($assignment['contract_id'] ?? 0));
        if (!is_array($contract)) {
            return $this->res->notFound('Contract template not found');
        }

        $assignedClauses = $quotationContractModel->resolveAssignedClauses((int) ($assignment['id'] ?? 0));

        return $this->res->ok($this->formatResponse($contract, $assignment, $assignedClauses), 'Quotation contract retrieved successfully');
    }

    public function assignToQuotation(int $quotationId)
    {
        $quotation = (new QuotationModel())->find($quotationId);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $data = $this->getRequestData(false);
        $contractId = (int) ($data['contractId'] ?? $data['contract_id'] ?? 0);
        if ($contractId < 1) {
            return $this->res->validation(['contractId' => 'A valid contract template id is required.']);
        }

        $contract = (new ContractModel())->findDetailed($contractId);
        if (!is_array($contract)) {
            return $this->res->notFound('Contract template not found');
        }

        $payload = [
            'quotation_id' => $quotationId,
            'contract_id' => $contractId,
            'owner_name' => $this->normalizeNullableText($data['ownerName'] ?? ($contract['owner_name'] ?? null), 190),
            'owner_signature' => $this->normalizeNullableText($data['ownerSignature'] ?? null, 65535),
            'recipient_name' => $this->normalizeNullableText($data['recipientName'] ?? null, 190),
            'recipient_signature' => $this->normalizeNullableText($data['recipientSignature'] ?? null, 65535),
        ];

        $errors = $this->validatePayload($payload);
        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        $quotationContractModel = new QuotationContractModel();
        $templateClauseIds = $this->extractTemplateClauseIds($contract);
        $clauseIds = $this->resolveClauseIds($data, $templateClauseIds);
        $clauseErrors = $quotationContractModel->validateClauseIds($clauseIds);
        if ($clauseErrors !== []) {
            return $this->res->validation($clauseErrors);
        }

        $saved = $quotationContractModel->saveAssignmentWithClauses($payload, $clauseIds);
        if (!is_array($saved)) {
            return $this->res->serverError('Quotation contract could not be saved.');
        }

        $template = (new ContractModel())->findDetailed((int) ($saved['contract_id'] ?? 0));
        if (!is_array($template)) {
            return $this->res->serverError('Contract template could not be loaded.');
        }

        $assignedClauses = $quotationContractModel->resolveAssignedClauses((int) ($saved['id'] ?? 0));

        return $this->res->ok($this->formatResponse($template, $saved, $assignedClauses), 'Contract template assigned to quotation successfully');
    }

    public function updateSignatures(int $quotationId)
    {
        $quotation = (new QuotationModel())->find($quotationId);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $model = new QuotationContractModel();
        $assignment = $model->findByQuotationId($quotationId);
        if (!is_array($assignment)) {
            return $this->res->notFound('Quotation contract not found');
        }

        $data = $this->getRequestData(false);
        $payload = [];

        if (array_key_exists('ownerName', $data)) {
            $payload['owner_name'] = $this->normalizeNullableText($data['ownerName'], 190);
        }

        if (array_key_exists('ownerSignature', $data)) {
            $payload['owner_signature'] = $this->normalizeNullableText($data['ownerSignature'], 65535);
        }

        if (array_key_exists('recipientName', $data)) {
            $payload['recipient_name'] = $this->normalizeNullableText($data['recipientName'], 190);
        }

        if (array_key_exists('recipientSignature', $data)) {
            $payload['recipient_signature'] = $this->normalizeNullableText($data['recipientSignature'], 65535);
        }

        if ($payload === []) {
            return $this->res->badRequest('No signature fields supplied to update.');
        }

        $merged = array_merge($assignment, $payload);
        $errors = $this->validatePayload($merged);
        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        $model->update((int) ($assignment['id'] ?? 0), $payload);

        $saved = $model->findByQuotationId($quotationId);
        if (!is_array($saved)) {
            return $this->res->serverError('Quotation contract could not be loaded.');
        }

        $template = (new ContractModel())->findDetailed((int) ($saved['contract_id'] ?? 0));
        if (!is_array($template)) {
            return $this->res->serverError('Contract template could not be loaded.');
        }

        $assignedClauses = $model->resolveAssignedClauses((int) ($saved['id'] ?? 0));

        return $this->res->ok($this->formatResponse($template, $saved, $assignedClauses), 'Quotation contract signatures updated successfully');
    }

    public function updateClauses(int $quotationId)
    {
        $quotation = (new QuotationModel())->find($quotationId);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $assignmentModel = new QuotationContractModel();
        $assignment = $assignmentModel->findByQuotationId($quotationId);
        if (!is_array($assignment)) {
            return $this->res->notFound('Quotation contract not found');
        }

        $data = $this->getRequestData(false);
        $clauseIds = $this->resolveClauseIds($data, []);
        $errors = $assignmentModel->validateClauseIds($clauseIds);
        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        $assignmentModel->syncAssignedClauses((int) ($assignment['id'] ?? 0), $clauseIds);

        $template = (new ContractModel())->findDetailed((int) ($assignment['contract_id'] ?? 0));
        if (!is_array($template)) {
            return $this->res->serverError('Contract template could not be loaded.');
        }

        $assignedClauses = $assignmentModel->resolveAssignedClauses((int) ($assignment['id'] ?? 0));

        return $this->res->ok($this->formatResponse($template, $assignment, $assignedClauses), 'Quotation contract clauses updated successfully');
    }

    private function validatePayload(array $payload): array
    {
        $errors = [];

        $ownerName = trim((string) ($payload['owner_name'] ?? ''));
        if ($ownerName === '' || mb_strlen($ownerName) > 190) {
            $errors['ownerName'] = 'Owner name is required and must not exceed 190 characters.';
        }

        $recipientName = trim((string) ($payload['recipient_name'] ?? ''));
        if ($recipientName === '' || mb_strlen($recipientName) > 190) {
            $errors['recipientName'] = 'Recipient name is required and must not exceed 190 characters.';
        }

        return $errors;
    }

    private function normalizeNullableText(mixed $value, int $maxLength = 65535): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $maxLength) {
            return mb_substr($text, 0, $maxLength);
        }

        return $text;
    }

    /**
     * @param array<int, array<string, mixed>> $assignedClauses
     */
    private function formatResponse(array $template, array $assignment, array $assignedClauses): array
    {
        $clauses = array_map(static function (array $clause): array {
            return [
                'id' => (int) ($clause['id'] ?? 0) ?: null,
                'title' => trim((string) ($clause['title'] ?? '')),
                'description' => trim((string) ($clause['description'] ?? '')),
            ];
        }, $assignedClauses);

        return [
            'id' => (int) ($assignment['id'] ?? 0) ?: null,
            'quotationId' => (int) ($assignment['quotation_id'] ?? 0) ?: null,
            'contractId' => (int) ($assignment['contract_id'] ?? 0) ?: null,
            'templateName' => $this->normalizeNullableText($template['template_name'] ?? null, 190),
            'templateDescription' => $this->normalizeNullableText($template['template_description'] ?? null, 65535),
            'contractName' => $this->normalizeNullableText($template['contract_name'] ?? null, 190),
            'contractDescription' => $this->normalizeNullableText($template['contract_description'] ?? null, 65535),
            'message' => $this->normalizeNullableText($template['message'] ?? null, 65535),
            'clauses' => $clauses,
            'ownerName' => $this->normalizeNullableText($assignment['owner_name'] ?? null, 190),
            'ownerSignature' => $this->normalizeNullableText($assignment['owner_signature'] ?? null, 65535),
            'recipientName' => $this->normalizeNullableText($assignment['recipient_name'] ?? null, 190),
            'recipientSignature' => $this->normalizeNullableText($assignment['recipient_signature'] ?? null, 65535),
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<int, int>
     */
    private function extractTemplateClauseIds(array $contract): array
    {
        $clauses = is_array($contract['clauses'] ?? null) ? $contract['clauses'] : [];
        $ids = array_map(static fn (array $clause): int => (int) ($clause['id'] ?? 0), $clauses);

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeClauseIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = array_map('intval', $value);

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, int> $defaultClauseIds
     * @return array<int, int>
     */
    private function resolveClauseIds(array $data, array $defaultClauseIds): array
    {
        if (array_key_exists('clauses_ids', $data)) {
            return $this->normalizeClauseIds($data['clauses_ids']);
        }

        if (array_key_exists('clauseIds', $data)) {
            return $this->normalizeClauseIds($data['clauseIds']);
        }

        if (array_key_exists('clause_ids', $data)) {
            return $this->normalizeClauseIds($data['clause_ids']);
        }

        return $defaultClauseIds;
    }
}
