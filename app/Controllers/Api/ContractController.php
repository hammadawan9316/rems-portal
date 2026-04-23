<?php

namespace App\Controllers\Api;

use App\Models\ContractClauseModel;
use App\Models\ContractModel;
use App\Models\ContractTemplateClauseModel;

class ContractController extends BaseApiController
{
    public function index()
    {
        $params = $this->getListQueryParams();
        $contractModel = new ContractModel();
        $result = $contractModel->paginateContracts($params['search'], $params['perPage'], $params['offset']);

        return $this->res->paginated(
            array_map(fn (array $contract): array => $this->formatContractForResponse($contract), $result['items']),
            $result['total'],
            $params['page'],
            $params['perPage'],
            'Contracts retrieved successfully'
        );
    }

    public function show(int $id)
    {
        $contractModel = new ContractModel();
        $contract = $contractModel->findDetailed($id);
        if (!is_array($contract)) {
            return $this->res->notFound('Contract not found');
        }

        return $this->res->ok($this->formatContractForResponse($contract), 'Contract retrieved successfully');
    }

    public function store()
    {
        $data = $this->getRequestData(false);
        $contractPayload = $this->buildContractPayload($data);
        $clauseIds = $this->normalizeClauseIds($data['clauseIds'] ?? ($data['clause_ids'] ?? []));

        $errors = $this->validateContractPayload($contractPayload, true);
        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        $clauseErrors = $this->validateClauseIds($clauseIds);
        if ($clauseErrors !== []) {
            return $this->res->validation($clauseErrors);
        }

        $contractModel = new ContractModel();
        $templateClauseModel = new ContractTemplateClauseModel();
        $db = db_connect();

        $db->transStart();
        $contractModel->insert($contractPayload);
        $contractId = (int) $contractModel->getInsertID();

        if ($contractId < 1) {
            $db->transRollback();
            return $this->res->serverError('Contract could not be created.');
        }

        $this->syncContractClauses($templateClauseModel, $contractId, $clauseIds);
        $db->transComplete();

        $contract = $contractModel->findDetailed($contractId);
        if (!is_array($contract)) {
            return $this->res->serverError('Contract could not be loaded after creation.');
        }

        return $this->res->created($this->formatContractForResponse($contract), 'Contract created successfully');
    }

    public function update(int $id)
    {
        $contractModel = new ContractModel();
        $templateClauseModel = new ContractTemplateClauseModel();
        $db = db_connect();

        $contract = $contractModel->findDetailed($id);
        if (!is_array($contract)) {
            return $this->res->notFound('Contract not found');
        }

        $data = $this->getRequestData(false);
        $contractPayload = $this->buildContractPayload($data, false);
        $clauseIds = null;

        if (array_key_exists('clauseIds', $data) || array_key_exists('clause_ids', $data)) {
            $clauseIds = $this->normalizeClauseIds($data['clauseIds'] ?? ($data['clause_ids'] ?? []));
            $clauseErrors = $this->validateClauseIds($clauseIds);
            if ($clauseErrors !== []) {
                return $this->res->validation($clauseErrors);
            }
        }

        $errors = $this->validateContractPayload($contractPayload, false);
        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        if ($contractPayload === [] && $clauseIds === null) {
            return $this->res->badRequest('No contract fields supplied to update.');
        }

        $db->transStart();

        if ($contractPayload !== []) {
            $contractModel->update($id, $contractPayload);
        }

        if ($clauseIds !== null) {
            $this->syncContractClauses($templateClauseModel, $id, $clauseIds);
        }

        $db->transComplete();

        $updated = $contractModel->findDetailed($id);
        if (!is_array($updated)) {
            return $this->res->notFound('Contract not found');
        }

        return $this->res->ok($this->formatContractForResponse($updated), 'Contract updated successfully');
    }

    public function delete(int $id)
    {
        $contractModel = new ContractModel();
        $contract = $contractModel->find($id);
        if (!is_array($contract)) {
            return $this->res->notFound('Contract not found');
        }

        $contractModel->delete($id);

        return $this->res->ok(null, 'Contract deleted successfully');
    }

    public function clauses()
    {
        $clauseModel = new ContractClauseModel();
        $params = $this->getListQueryParams();

        $countBuilder = $clauseModel->builder();
        if ($params['search'] !== '') {
            $countBuilder->groupStart()
                ->like('title', $params['search'])
                ->orLike('description', $params['search'])
                ->groupEnd();
        }

        $total = (int) (($countBuilder->select('COUNT(*) AS total', false)->get()->getRowArray()['total'] ?? 0));

        $itemsBuilder = $clauseModel->builder()->select('*');
        if ($params['search'] !== '') {
            $itemsBuilder->groupStart()
                ->like('title', $params['search'])
                ->orLike('description', $params['search'])
                ->groupEnd();
        }

        $rows = $itemsBuilder
            ->orderBy('id', 'DESC')
            ->limit($params['perPage'], $params['offset'])
            ->get()
            ->getResultArray();

        return $this->res->paginated($rows, $total, $params['page'], $params['perPage'], 'Clauses retrieved successfully');
    }

    public function storeClause()
    {
        $clauseModel = new ContractClauseModel();

        $data = $this->getRequestData(false);
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Clause title is required.';
        } elseif (mb_strlen($title) > 190) {
            $errors['title'] = 'Clause title must not exceed 190 characters.';
        }

        if ($description !== '' && mb_strlen($description) > 65535) {
            $errors['description'] = 'Clause description must not exceed 65535 characters.';
        }

        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        $clauseModel->insert([
            'title' => $title,
            'description' => $description !== '' ? $description : null,
        ]);

        $clauseId = (int) $clauseModel->getInsertID();

        return $this->res->created($clauseModel->find($clauseId), 'Clause created successfully');
    }

    public function updateClause(int $clauseId)
    {
        $clauseModel = new ContractClauseModel();
        $clause = $clauseModel->find($clauseId);
        if (!is_array($clause)) {
            return $this->res->notFound('Clause not found');
        }

        $data = $this->getRequestData(false);
        $payload = [];

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '' || mb_strlen($title) > 190) {
                return $this->res->validation(['title' => 'Clause title must not be empty and must not exceed 190 characters.']);
            }
            $payload['title'] = $title;
        }

        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            $payload['description'] = $description !== '' ? $description : null;
        }

        if ($payload === []) {
            return $this->res->badRequest('No clause fields supplied to update.');
        }

        $clauseModel->update($clauseId, $payload);

        return $this->res->ok($clauseModel->find($clauseId), 'Clause updated successfully');
    }

    public function deleteClause(int $clauseId)
    {
        $clauseModel = new ContractClauseModel();
        $clause = $clauseModel->find($clauseId);
        if (!is_array($clause)) {
            return $this->res->notFound('Clause not found');
        }

        $clauseModel->delete($clauseId);

        return $this->res->ok(null, 'Clause deleted successfully');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildContractPayload(array $data, bool $requireContractName = true): array
    {
        $payload = [];

        foreach ([
            'template_name' => 'templateName',
            'template_description' => 'templateDescription',
            'contract_name' => 'contractName',
            'contract_description' => 'contractDescription',
            'message' => 'message',
            'owner_name' => 'ownerName',
        ] as $column => $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $this->normalizeNullableText($data[$field], $column === 'message' || str_contains($column, 'signature') ? 1000000 : 190);
            $payload[$column] = $value;
        }

        if ($requireContractName && !array_key_exists('contractName', $data)) {
            return $payload;
        }

        return $payload;
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
     * @param array<int, int> $clauseIds
     * @return array<string, string>
     */
    private function validateClauseIds(array $clauseIds): array
    {
        if ($clauseIds === []) {
            return [
                'clauseIds' => 'At least one valid clause id is required.',
            ];
        }

        $rows = model(ContractClauseModel::class)->whereIn('id', $clauseIds)->findAll();
        $foundIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
        $foundIds = array_values(array_filter($foundIds, static fn (int $id): bool => $id > 0));

        $missing = array_values(array_diff($clauseIds, $foundIds));
        if ($missing !== []) {
            return [
                'clauseIds' => 'Clause ids not found: ' . implode(', ', $missing) . '.',
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function formatContractForResponse(array $contract): array
    {
        $clauses = [];
        foreach (is_array($contract['clauses'] ?? null) ? $contract['clauses'] : [] as $clause) {
            if (!is_array($clause)) {
                continue;
            }

            $clauses[] = [
                'id' => (int) ($clause['id'] ?? 0) ?: null,
                'title' => $this->normalizeNullableText($clause['title'] ?? null, 190) ?? '',
                'description' => $this->normalizeNullableText($clause['description'] ?? null, 1000000) ?? '',
            ];
        }

        return [
            'id' => (int) ($contract['id'] ?? 0) ?: null,
            'templateName' => $this->normalizeNullableText($contract['template_name'] ?? null, 190),
            'templateDescription' => $this->normalizeNullableText($contract['template_description'] ?? null, 1000000),
            'contractName' => $this->normalizeNullableText($contract['contract_name'] ?? null, 190),
            'contractDescription' => $this->normalizeNullableText($contract['contract_description'] ?? null, 1000000),
            'message' => $this->normalizeNullableText($contract['message'] ?? null, 1000000),
            'clauses' => $clauses,
            'ownerName' => $this->normalizeNullableText($contract['owner_name'] ?? null, 190),
        ];
    }

    /**
     * @param array<int, int> $clauseIds
     */
    private function syncContractClauses(ContractTemplateClauseModel $templateClauseModel, int $contractId, array $clauseIds): void
    {
        $templateClauseModel->where('contract_id', $contractId)->delete();

        foreach (array_values($clauseIds) as $index => $clauseId) {
            $templateClauseModel->insert([
                'contract_id' => $contractId,
                'clause_id' => $clauseId,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validateContractPayload(array $data, bool $isCreate): array
    {
        $errors = [];

        if ($isCreate && !array_key_exists('contract_name', $data)) {
            $errors['contractName'] = 'Contract name is required.';
        }

        $nameFields = [
            'contract_name' => 'contractName',
            'template_name' => 'templateName',
            'owner_name' => 'ownerName',
        ];

        foreach ($nameFields as $field => $errorKey) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = trim((string) $data[$field]);
            if ($value === '') {
                $errors[$errorKey] = ucfirst(str_replace('_', ' ', $field)) . ' cannot be empty.';
            } elseif (mb_strlen($value) > 190) {
                $errors[$errorKey] = ucfirst(str_replace('_', ' ', $field)) . ' must not exceed 190 characters.';
            }
        }

        $textFields = [
            'template_description' => 'templateDescription',
            'contract_description' => 'contractDescription',
            'message' => 'message',
        ];

        foreach ($textFields as $field => $errorKey) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            if (is_string($value) && mb_strlen(trim($value)) > 65535) {
                $errors[$errorKey] = ucfirst(str_replace('_', ' ', $field)) . ' must not exceed 65535 characters.';
            }
        }

        return $errors;
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableText(mixed $value, int $maxLength = 1000000): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if ($text === '') {
            return null;
        }

        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            return mb_substr($text, 0, $maxLength);
        }

        return $text;
    }
}
