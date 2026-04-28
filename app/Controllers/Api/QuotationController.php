<?php

namespace App\Controllers\Api;

use App\Models\BusinessProfileModel;
use App\Models\CategoryModel;
use App\Models\ContractModel;
use App\Models\CustomerModel;
use App\Models\ProjectFileModel;
use App\Models\ProjectModel;
use App\Models\ProjectServiceModel;
use App\Models\QuotationContractModel;
use App\Models\QuotationModel;
use App\Models\QuotationRequestModel;
use App\Models\QuotationRequestProjectModel;
use App\Models\ServiceModel;
use App\Libraries\SquareProjectQueueService;
use App\Libraries\SquareService;
use Config\Database;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class QuotationController extends BaseApiController
{
    private const RESPONSE_ACTOR_ADMIN = 'admin';
    private const RESPONSE_ACTOR_CUSTOMER = 'customer';
    private const PUBLIC_TOKEN_EXPIRY_DAYS = 7;

    private const STATUS_REQUESTED = 'requested';
    private const STATUS_DRAFT = 'draft';
    private const STATUS_PENDING = 'pending';
    private const STATUS_ACCEPTED = 'accepted';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_SQUARE_FAILED = 'square_failed';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
        self::STATUS_SQUARE_FAILED,
    ];

    /**
     * @var array<int, string>
     */
    private const CUSTOMER_ALLOWED_RESPONSE_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_REJECTED,
    ];

    public function store()
    {
        return $this->submit();
    }

    public function submit()
    {
        $data = $this->normalizeIncomingPayload($this->getRequestData(false));
        $requestId = (int) ($data['request_id'] ?? ($data['source_request_id'] ?? 0));
        $contractId = (int) ($data['contract_id'] ?? ($data['contractId'] ?? 0));

        $request = null;
        $projectItems = [];

        $quotationRequestModel = new QuotationRequestModel();
        $quotationRequestProjectModel = new QuotationRequestProjectModel();
        $quotationModel = new QuotationModel();

        if ($requestId > 0) {
            $request = $quotationRequestModel->find($requestId);
            if (!is_array($request)) {
                return $this->res->notFound('Quotation request not found.');
            }

            $existingQuotation = $quotationModel->where('source_request_id', $requestId)->first();
            if (is_array($existingQuotation)) {
                return $this->res->ok([
                    'request_id' => $requestId,
                    'source_request_id' => $requestId,
                    'quotation_id' => (int) ($existingQuotation['id'] ?? 0),
                ], 'Quotation already exists for this request.');
            }

            $requestProjects = $quotationRequestProjectModel
                ->where('quotation_request_id', $requestId)
                ->orderBy('request_project_index', 'ASC')
                ->findAll();

            $projectItems = array_key_exists('projects', $data) || $this->looksLikeProjectPayload($data)
                ? $this->extractProjectItems($data)
                : $this->extractProjectItemsFromRequest($requestProjects);
        } else {
            $projectItems = $this->extractProjectItems($data);
        }

        if ($projectItems === []) {
            return $this->res->badRequest('At least one project is required.', [
                'projects' => 'Provide a projects array with one or more items.',
            ]);
        }

        $requestErrors = $this->validateNormalizedRequest($data);
        if ($requestErrors !== []) {
            return $this->res->validation($requestErrors);
        }

        $projectErrors = $this->validateProjectItems($projectItems);
        if ($projectErrors !== []) {
            return $this->res->validation($projectErrors);
        }

        $taxonomyResolution = $this->resolveProjectTaxonomy($projectItems);
        if (($taxonomyResolution['errors'] ?? []) !== []) {
            return $this->res->validation($taxonomyResolution['errors']);
        }

        $projectItems = $taxonomyResolution['projects'] ?? $projectItems;

        $projectModel = new ProjectModel();
        $projectServiceModel = new ProjectServiceModel();
        $projectFileModel = new ProjectFileModel();
        $customerModel = new CustomerModel();
        $quotationContractModel = new QuotationContractModel();
        $contractModel = new ContractModel();

        $contract = null;
        if ($contractId > 0) {
            $contract = $contractModel->findDetailed($contractId);
            if (!is_array($contract)) {
                return $this->res->notFound('Contract template not found.');
            }
        }

        $customerId = (int) ($data['customer_id'] ?? (($request['customer_id'] ?? 0)));
        if ($customerId < 1) {
            return $this->res->badRequest('Customer id is required.', [
                'customer_id' => 'A valid customer id is required.',
            ]);
        }

        $customer = $customerModel->find($customerId);
        if (!is_array($customer)) {
            return $this->res->notFound('Customer not found.');
        }

        $quoteNumber = $quotationModel->generateQuoteNumber();
        $businessSnapshot = $this->resolveBusinessSnapshotPayload($data);
        if (isset($businessSnapshot['error'])) {
            return $this->res->badRequest((string) $businessSnapshot['error'], [
                'business_profile_id' => (string) $businessSnapshot['error'],
            ]);
        }

        $quotationPayload = [
            'customer_id' => $customerId,
            'source_request_id' => $requestId > 0 ? $requestId : null,
            'business_profile_id' => $businessSnapshot['business_profile_id'] ?? null,
            'business_name' => $businessSnapshot['business_name'] ?? null,
            'business_admin_name' => $businessSnapshot['business_admin_name'] ?? null,
            'business_email' => $businessSnapshot['business_email'] ?? null,
            'business_phone' => $businessSnapshot['business_phone'] ?? null,
            'business_address' => $businessSnapshot['business_address'] ?? null,
            'business_website_url' => $businessSnapshot['business_website_url'] ?? null,
            'quote_number' => $quoteNumber,
            'description' => $this->normalizeNullableText($data['description'] ?? ($data['title'] ?? ($request['description'] ?? null))),
            'status' => self::STATUS_DRAFT,
            'notes' => $this->normalizeNullableText($data['notes'] ?? ($request['notes'] ?? null)),
            'submitted_at' => date('Y-m-d H:i:s'),
            'discount_type' => $this->normalizeDiscountType($data['discount_type'] ?? ($data['discountType'] ?? null)),
            'discount_value' => $this->normalizeDecimalValue($data['discount_value'] ?? ($data['discountValue'] ?? null)),
            'discount_scope' => $this->normalizeDiscountScope($data['discount_scope'] ?? ($data['discountScope'] ?? null)),
        ];

        $db = Database::connect();
        $db->transStart();

        $quotationModel->insert($quotationPayload);

        $quotationId = (int) $quotationModel->getInsertID();
        if ($quotationId < 1) {
            $db->transRollback();
            return $this->res->serverError('Quotation could not be created.');
        }

        $quotationContractId = null;
        if (is_array($contract)) {
            $businessOwnerPayload = $this->buildQuotationContractBusinessPayload($businessSnapshot);
            $savedAssignment = $quotationContractModel->saveAssignmentWithClauses([
                'quotation_id' => $quotationId,
                'contract_id' => $contractId,
                'owner_name' => $this->normalizeNullableText($data['ownerName'] ?? ($businessOwnerPayload['owner_name'] ?? ($contract['owner_name'] ?? null))),
                'owner_signature' => $this->normalizeNullableText($data['ownerSignature'] ?? ($businessOwnerPayload['owner_signature'] ?? ($data['owner_signature'] ?? null))),
                'owner_signed_at' => $this->normalizeDateTimeString($data['ownerSignedAt'] ?? ($data['owner_signed_at'] ?? null)) ?? ($businessOwnerPayload['owner_signed_at'] ?? null),
                'recipient_name' => $this->normalizeNullableText($data['recipientName'] ?? ($data['recipient_name'] ?? ($request['client_name'] ?? null))),
                'recipient_signature' => $this->normalizeNullableText($data['recipientSignature'] ?? ($data['recipient_signature'] ?? null)),
                'recipient_signed_at' => $this->normalizeDateTimeString($data['dateSigned'] ?? ($data['signedAt'] ?? ($data['recipient_signed_at'] ?? null))),
            ], $this->extractTemplateClauseIds($contract));

            if (!is_array($savedAssignment)) {
                $db->transRollback();

                return $this->res->serverError('Contract could not be assigned to quotation.');
            }

            $quotationContractId = (int) ($savedAssignment['id'] ?? 0) ?: null;
        }

        $createdProjects = [];
        foreach ($projectItems as $item) {
            $projectData = [
                'customer_id' => $customerId,
                'quotation_id' => $quotationId,
                'category_id' => (int) ($item['category_id'] ?? 0) ?: null,
                'project_title' => $item['project_title'],
                'project_description' => $item['project_description'],
                'scope' => $item['scope'],
                'estimate_type' => $item['estimate_type'],
                'plans_url' => null,
                'zip_code' => $item['zip_code'],
                'deadline' => $item['deadline'],
                'delivery_date' => $item['delivery_date'],
                'deadline_date' => $item['deadline_date'],
                'estimated_amount' => $item['estimated_amount'],
                'payment_type' => $item['payment_type'],
                'hourly_hours' => $item['hourly_hours'],
                'status' => 'submitted',
            ];

            $projectModel->insert($projectData);
            $projectId = (int) $projectModel->getInsertID();
            $projectServiceModel->replaceServices($projectId, is_array($item['service_ids'] ?? null) ? $item['service_ids'] : []);

            if ($requestId > 0) {
                $requestProjectIndex = (int) ($item['_request_project_index'] ?? 0);
                $projectFileModel
                    ->where('quotation_request_id', $requestId)
                    ->where('request_project_index', $requestProjectIndex)
                    ->set([
                        'project_id' => $projectId,
                    ])
                    ->update();
            }

            $savedProject = $projectModel->find($projectId);
            if (is_array($savedProject)) {
                $createdProjects[] = $savedProject;
            }
        }

        if ($requestId > 0) {
            $quotationRequestModel->update($requestId, [
                'status' => 'quoted',
                'quoted_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->res->serverError('Quotation could not be created.');
        }

        $quotation = $quotationModel->find($quotationId);
        if (!is_array($quotation)) {
            $quotation = [];
        }
        $quotation = $this->formatQuotationForResponse($quotation, $customer);

        $quotation['projects'] = $this->formatProjectsForResponse($createdProjects);
        $quotation['project_count'] = count($createdProjects);
        $quotation['request_id'] = $requestId > 0 ? $requestId : null;
        $quotation['contract_id'] = $contractId > 0 ? $contractId : null;
        $quotation['quotation_contract_id'] = $quotationContractId;

        return $this->res->created($quotation, 'Quotation created successfully from projects. Square invoice creation is deferred until explicitly requested by admin.');
    }

    public function createSquareInvoice(int $id)
    {
        $quotationModel = new QuotationModel();
        $projectModel = new ProjectModel();
        $squareQueue = new SquareProjectQueueService();
        $square = new SquareService();

        $quotation = $quotationModel->find($id);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        if (!$square->isConfigured()) {
            return $this->res->badRequest('Square integration is not configured.');
        }

        $projects = $projectModel
            ->where('quotation_id', $id)
            ->orderBy('id', 'ASC')
            ->findAll();

        if ($projects === []) {
            return $this->res->badRequest('Cannot create Square invoice for a quotation without projects.');
        }

        $existingInvoiceId = trim((string) ($quotation['square_invoice_id'] ?? ''));
        if ($existingInvoiceId !== '') {
            return $this->res->badRequest('Square invoice is already linked to this quotation.', [
                'square_invoice_id' => $existingInvoiceId,
            ]);
        }

        $jobId = $squareQueue->enqueue($id);

        return $this->res->ok([
            'quotation_id' => $id,
            'queue_job_id' => $jobId,
            'square_processing' => 'queued_for_cron',
        ], 'Square invoice creation has been queued.');
    }

    public function sendPublicResponseLink(int $id)
    {
        $quotationModel = new QuotationModel();
        $customerModel = new CustomerModel();

        $quotation = $quotationModel->find($id);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $status = strtolower(trim((string) ($quotation['status'] ?? '')));
        if (!in_array($status, self::CUSTOMER_ALLOWED_RESPONSE_STATUSES, true)) {
            return $this->res->badRequest('Public response link can only be sent for quotations in requested, draft, pending or rejected status.');
        }

        if ($status !== self::STATUS_PENDING) {
            $updated = $quotationModel->update($id, [
                'status' => self::STATUS_PENDING,
            ]);

            if (!$updated) {
                return $this->res->serverError('Could not update quotation status to pending before sending link.');
            }

            $quotation['status'] = self::STATUS_PENDING;
        }

        $customerId = (int) ($quotation['customer_id'] ?? 0);
        $customer = $customerModel->find($customerId);
        if (!is_array($customer)) {
            return $this->res->notFound('Customer not found.');
        }

        $customerEmail = trim((string) ($customer['email'] ?? ''));
        if ($customerEmail === '') {
            return $this->res->badRequest('Customer does not have a valid email address.');
        }

        $tokenResult = $quotationModel->issuePublicResponseToken($id, self::PUBLIC_TOKEN_EXPIRY_DAYS);
        if (!is_array($tokenResult)) {
            return $this->res->serverError('Could not generate quotation response link.');
        }

        $plainToken = (string) ($tokenResult['token'] ?? '');
        $expiresAt = (string) ($tokenResult['expires_at'] ?? '');
        $quotationPreviewUrl = $this->buildPublicQuotationPreviewUrl($plainToken);
        $acceptUrl = $this->buildPublicQuotationActionUrl($plainToken, 'accept');
        $rejectUrl = $this->buildPublicQuotationActionUrl($plainToken, 'reject');
        $contractPreviewUrl = $this->buildPublicContractPreviewUrl($plainToken);
        $quoteNumber = trim((string) ($quotation['quote_number'] ?? ''));
        $recipientName = trim((string) ($customer['name'] ?? ''));
        $recipientName = $recipientName !== '' ? $recipientName : 'Customer';

        $emailQueue = service('emailQueue');
        $subject = 'Quotation Response Requested' . ($quoteNumber !== '' ? ' - ' . $quoteNumber : '');
        $expiryHuman = $this->formatDateTimeForEmail($expiresAt);

        $formattedQuotation = $this->formatQuotationForResponse($quotation, $customer);
        $projects = model(ProjectModel::class)
            ->where('quotation_id', $id)
            ->orderBy('id', 'ASC')
            ->findAll();
        $projects = $this->formatProjectsForResponse($projects);

        $assignment = model(QuotationContractModel::class)->findByQuotationId($id);
        $contractName = null;
        if (is_array($assignment)) {
            $contract = (new ContractModel())->findDetailed((int) ($assignment['contract_id'] ?? 0));
            if (is_array($contract)) {
                $contractName = $this->normalizeNullableText($contract['contract_name'] ?? null);
            }
        }

        $contentHtml = $this->buildQuotationDocumentHtml($formattedQuotation, $projects, [
            'showActions' => true,
            'acceptUrl' => $acceptUrl,
            'rejectUrl' => $rejectUrl,
            'contractUrl' => $contractPreviewUrl,
            'publicToken' => $plainToken,
            'contractName' => $contractName,
            'expiryLabel' => $expiryHuman,
            'quotationStatus' => $status,
        ]);

        $body = $emailQueue->renderTemplate([
            'subject' => $subject,
            'recipientName' => $recipientName,
            'headline' => 'Review Your Quotation',
            'contentHtml' => $contentHtml,
            'actionText' => 'Open Quotation Preview',
            'actionUrl' => $quotationPreviewUrl,
        ]);

        $queueId = queue_email_job($customerEmail, $subject, $body, [
            'mail_type' => 'html',
        ]);

        return $this->res->ok([
            'quotation_id' => $id,
            'queue_job_id' => $queueId,
            'expires_at' => $expiresAt,
            'quotation_preview_url' => $quotationPreviewUrl,
            'quotation_accept_url' => $acceptUrl,
            'quotation_reject_url' => $rejectUrl,
            'contract_preview_url' => $contractPreviewUrl,
            'customer_email_masked' => $this->maskEmail($customerEmail),
        ], 'Public quotation response email queued successfully.');
    }

    public function publicShow(string $token)
    {
        $quotationModel = new QuotationModel();
        $projectModel = new ProjectModel();

        $quotation = $quotationModel->findByPublicResponseToken($token);
        if (!is_array($quotation)) {
            return $this->res->unauthorized('Invalid or expired quotation response link.');
        }

        if (!$this->isPublicTokenActive($quotation)) {
            return $this->res->unauthorized('Quotation response link is expired or already used.');
        }

        $customer = model(CustomerModel::class)->find((int) ($quotation['customer_id'] ?? 0));
        $quotation = $this->formatQuotationForResponse($quotation, is_array($customer) ? $customer : null);
        $assignment = model(QuotationContractModel::class)->findByQuotationId((int) ($quotation['id'] ?? 0));
        $quotation['quotation_contract_id'] = is_array($assignment) ? ((int) ($assignment['id'] ?? 0) ?: null) : null;
        $quotation = $this->sanitizeQuotationForPublicResponse($quotation);

        $projects = $projectModel
            ->where('quotation_id', (int) ($quotation['id'] ?? 0))
            ->orderBy('id', 'ASC')
            ->findAll();

        $projects = $this->formatProjectsForResponse($projects);

        return $this->res->ok([
            'quotation' => $quotation,
            'projects' => $projects,
            'project_count' => count($projects),
        ], 'Quotation retrieved successfully.');
    }

    public function publicRespond(string $token)
    {
        $quotationModel = new QuotationModel();
        $projectModel = new ProjectModel();

        $quotation = $quotationModel->findByPublicResponseToken($token);
        if (!is_array($quotation)) {
            return $this->res->unauthorized('Invalid or expired quotation response link.');
        }

        $currentStatus = strtolower(trim((string) ($quotation['status'] ?? '')));
        if (!$this->isPublicTokenActive($quotation)) {
            if ($this->isTerminalStatus($currentStatus)) {
                return $this->response
                    ->setStatusCode(409)
                    ->setJSON([
                        'status' => false,
                        'message' => 'This quotation has already been finalized.',
                        'code' => 409,
                        'data' => [
                            'status' => $currentStatus,
                        ],
                    ]);
            }

            return $this->res->unauthorized('Quotation response link is expired or already used.');
        }

        if (!in_array($currentStatus, self::CUSTOMER_ALLOWED_RESPONSE_STATUSES, true)) {
            return $this->response
                ->setStatusCode(409)
                ->setJSON([
                    'status' => false,
                    'message' => 'This quotation can no longer be responded to.',
                    'code' => 409,
                    'data' => [
                        'status' => $currentStatus,
                    ],
                ]);
        }

        $data = $this->getRequestData(false);
        $decision = $this->resolveDecisionStatus($data['decision'] ?? ($data['action'] ?? ($data['status'] ?? null)));
        if ($decision === null) {
            return $this->res->validation([
                'decision' => 'Decision must be accept/accepted or reject/rejected.',
            ]);
        }

        $responseReason = $this->normalizeNullableText($data['reason'] ?? ($data['response_reason'] ?? null));
        $responseAt = date('Y-m-d H:i:s');

        $quotationModel->update((int) $quotation['id'], [
            'status' => $decision,
            'response_reason' => $responseReason,
            'response_actor' => self::RESPONSE_ACTOR_CUSTOMER,
            'response_at' => $responseAt,
        ]);

        $updatedQuotation = $quotationModel->find((int) $quotation['id']);
        if (!is_array($updatedQuotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $customer = model(CustomerModel::class)->find((int) ($updatedQuotation['customer_id'] ?? 0));
        $updatedQuotation = $this->formatQuotationForResponse($updatedQuotation, is_array($customer) ? $customer : null);
        $assignment = model(QuotationContractModel::class)->findByQuotationId((int) ($updatedQuotation['id'] ?? 0));
        $updatedQuotation['quotation_contract_id'] = is_array($assignment) ? ((int) ($assignment['id'] ?? 0) ?: null) : null;
        $updatedQuotation = $this->sanitizeQuotationForPublicResponse($updatedQuotation);

        $projects = $projectModel
            ->where('quotation_id', (int) $updatedQuotation['id'])
            ->orderBy('id', 'ASC')
            ->findAll();

        $projects = $this->formatProjectsForResponse($projects);

        return $this->res->ok([
            'quotation' => $updatedQuotation,
            'projects' => $projects,
            'project_count' => count($projects),
        ], 'Quotation response recorded successfully.');
    }

    public function update(int $id)
    {
        $quotationModel = new QuotationModel();
        $projectModel = new ProjectModel();
        $projectServiceModel = new ProjectServiceModel();
        $customerModel = new CustomerModel();
        $quotationContractModel = new QuotationContractModel();
        $contractModel = new ContractModel();

        $quotation = $quotationModel->find($id);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $data = $this->normalizeIncomingPayload($this->getRequestData(false));
        $quotationPayload = [];

        if (array_key_exists('customer_id', $data)) {
            $customerId = (int) ($data['customer_id'] ?? 0);
            if ($customerId < 1) {
                return $this->res->badRequest('Customer id is required.', [
                    'customer_id' => 'A valid customer id is required.',
                ]);
            }

            $customer = $customerModel->find($customerId);
            if (!is_array($customer)) {
                return $this->res->notFound('Customer not found.');
            }

            $quotationPayload['customer_id'] = $customerId;
        }

        if (array_key_exists('description', $data) || array_key_exists('title', $data)) {
            $quotationPayload['description'] = $this->normalizeNullableText($data['description'] ?? ($data['title'] ?? null));
        }

        if (array_key_exists('business_profile_id', $data) || array_key_exists('businessProfileId', $data)) {
            $businessSnapshot = $this->resolveBusinessSnapshotPayload($data, true);
            if (isset($businessSnapshot['error'])) {
                return $this->res->badRequest((string) $businessSnapshot['error'], [
                    'business_profile_id' => (string) $businessSnapshot['error'],
                ]);
            }

            $quotationPayload['business_profile_id'] = $businessSnapshot['business_profile_id'] ?? null;
            $quotationPayload['business_name'] = $businessSnapshot['business_name'] ?? null;
            $quotationPayload['business_admin_name'] = $businessSnapshot['business_admin_name'] ?? null;
            $quotationPayload['business_email'] = $businessSnapshot['business_email'] ?? null;
            $quotationPayload['business_phone'] = $businessSnapshot['business_phone'] ?? null;
            $quotationPayload['business_address'] = $businessSnapshot['business_address'] ?? null;
            $quotationPayload['business_website_url'] = $businessSnapshot['business_website_url'] ?? null;
        }

        $responseReasonProvided = array_key_exists('response_reason', $data) || array_key_exists('reason', $data);
        $responseReason = $this->normalizeNullableText($data['response_reason'] ?? ($data['reason'] ?? null));
        $statusChanged = false;

        if (array_key_exists('status', $data)) {
            $statusResult = $this->resolveStatusFilter($data['status']);
            if (is_array($statusResult) && isset($statusResult['error'])) {
                return $this->res->badRequest('Invalid quotation status.', [
                    'status' => (string) $statusResult['error'],
                ]);
            }

            $quotationPayload['status'] = is_string($statusResult) ? $statusResult : self::STATUS_PENDING;
            $statusChanged = true;

            if ($this->isTerminalStatus((string) $quotationPayload['status']) || $responseReasonProvided) {
                $quotationPayload['response_reason'] = $responseReason;
                $quotationPayload['response_actor'] = self::RESPONSE_ACTOR_ADMIN;
                $quotationPayload['response_at'] = date('Y-m-d H:i:s');
            }
        } elseif ($responseReasonProvided) {
            $quotationPayload['response_reason'] = $responseReason;
            $quotationPayload['response_actor'] = self::RESPONSE_ACTOR_ADMIN;
            $quotationPayload['response_at'] = date('Y-m-d H:i:s');
        }

        if (array_key_exists('notes', $data)) {
            $quotationPayload['notes'] = $this->normalizeNullableText($data['notes']);
        }

        if (array_key_exists('discount_type', $data) || array_key_exists('discountType', $data)) {
            $quotationPayload['discount_type'] = $this->normalizeDiscountType($data['discount_type'] ?? ($data['discountType'] ?? null));
        }

        if (array_key_exists('discount_value', $data) || array_key_exists('discountValue', $data)) {
            $quotationPayload['discount_value'] = $this->normalizeDecimalValue($data['discount_value'] ?? ($data['discountValue'] ?? null));
        }

        if (array_key_exists('discount_scope', $data) || array_key_exists('discountScope', $data)) {
            $quotationPayload['discount_scope'] = $this->normalizeDiscountScope($data['discount_scope'] ?? ($data['discountScope'] ?? null));
        }

        $contractAssignmentRequested = array_key_exists('contract_id', $data) || array_key_exists('contractId', $data);
        $contractToAssign = null;

        if ($contractAssignmentRequested) {
            $contractId = (int) ($data['contract_id'] ?? ($data['contractId'] ?? 0));
            if ($contractId < 1) {
                return $this->res->badRequest('Contract id is required.', [
                    'contract_id' => 'A valid contract id is required.',
                ]);
            }

            $contractToAssign = $contractModel->findDetailed($contractId);
            if (!is_array($contractToAssign)) {
                return $this->res->notFound('Contract template not found.');
            }
        }

        $shouldReplaceProjects = isset($data['projects']) || $this->looksLikeProjectPayload($data);
        $projectItems = [];

        if ($shouldReplaceProjects) {
            $projectItems = $this->extractProjectItems($data);
            if ($projectItems === []) {
                return $this->res->badRequest('At least one project is required.', [
                    'projects' => 'Provide a projects array with one or more items.',
                ]);
            }

            $projectErrors = $this->validateProjectItems($projectItems);
            if ($projectErrors !== []) {
                return $this->res->validation($projectErrors);
            }

            $taxonomyResolution = $this->resolveProjectTaxonomy($projectItems);
            if (($taxonomyResolution['errors'] ?? []) !== []) {
                return $this->res->validation($taxonomyResolution['errors']);
            }

            $projectItems = $taxonomyResolution['projects'] ?? $projectItems;
        }

        if ($quotationPayload === [] && !$shouldReplaceProjects && !$contractAssignmentRequested) {
            return $this->res->badRequest('No quotation fields supplied to update.');
        }

        if ($quotationPayload !== []) {
            $quotationModel->update($id, $quotationPayload);

            if ($statusChanged && isset($quotationPayload['status']) && $this->isTerminalStatus((string) $quotationPayload['status'])) {
                $quotationModel->invalidatePublicResponseToken($id);
            }
        }

        $effectiveCustomerId = (int) ($quotationPayload['customer_id'] ?? ($quotation['customer_id'] ?? 0));
        $customer = $customerModel->find($effectiveCustomerId);
        if (!is_array($customer)) {
            return $this->res->notFound('Customer not found.');
        }

        if ($shouldReplaceProjects) {
            $existingProjects = $projectModel
                ->where('quotation_id', $id)
                ->orderBy('id', 'ASC')
                ->findAll();

            $existingProjectIds = array_map(static fn(array $project): int => (int) ($project['id'] ?? 0), $existingProjects);
            $existingProjectIds = array_values(array_filter($existingProjectIds, static fn(int $projectId): bool => $projectId > 0));

            if ($existingProjectIds !== []) {
                $projectServiceModel->whereIn('project_id', $existingProjectIds)->delete();
            }

            $projectModel->where('quotation_id', $id)->delete();

            foreach ($projectItems as $item) {
                $projectData = [
                    'customer_id' => $effectiveCustomerId,
                    'quotation_id' => $id,
                    'category_id' => (int) ($item['category_id'] ?? 0) ?: null,
                    'project_title' => $item['project_title'],
                    'project_description' => $item['project_description'],
                    'scope' => $item['scope'],
                    'estimate_type' => $item['estimate_type'],
                    'plans_url' => null,
                    'zip_code' => $item['zip_code'],
                    'deadline' => $item['deadline'],
                    'delivery_date' => $item['delivery_date'],
                    'deadline_date' => $item['deadline_date'],
                    'estimated_amount' => $item['estimated_amount'],
                    'payment_type' => $item['payment_type'],
                    'hourly_hours' => $item['hourly_hours'],
                    'status' => 'submitted',
                ];

                $projectModel->insert($projectData);
                $projectId = (int) $projectModel->getInsertID();
                $projectServiceModel->replaceServices($projectId, is_array($item['service_ids'] ?? null) ? $item['service_ids'] : []);
            }
        }

        if ($contractAssignmentRequested && is_array($contractToAssign)) {
            $businessOwnerPayload = $this->buildQuotationContractBusinessPayload($quotation);
            $savedAssignment = $quotationContractModel->saveAssignmentWithClauses([
                'quotation_id' => $id,
                'contract_id' => (int) ($contractToAssign['id'] ?? 0),
                'owner_name' => $this->normalizeNullableText($data['ownerName'] ?? ($businessOwnerPayload['owner_name'] ?? ($quotation['business_admin_name'] ?? ($quotation['business_name'] ?? null)))),
                'owner_signature' => $this->normalizeNullableText($data['ownerSignature'] ?? ($businessOwnerPayload['owner_signature'] ?? ($quotation['business_admin_name'] ?? ($quotation['business_name'] ?? null)))),
                'owner_signed_at' => $this->normalizeDateTimeString($data['ownerSignedAt'] ?? ($data['owner_signed_at'] ?? null)) ?? ($businessOwnerPayload['owner_signed_at'] ?? null),
                'recipient_name' => $this->normalizeNullableText($data['recipientName'] ?? ($quotation['customer_name'] ?? null)),
                'recipient_signature' => $this->normalizeNullableText($data['recipientSignature'] ?? ($data['recipient_signature'] ?? null)),
                'recipient_signed_at' => $this->normalizeDateTimeString($data['recipientSignedAt'] ?? ($data['recipient_signed_at'] ?? null)),
            ], $this->extractTemplateClauseIds($contractToAssign));

            if (!is_array($savedAssignment)) {
                return $this->res->serverError('Contract could not be assigned to quotation.');
            }
        }

        $updatedQuotation = $quotationModel->find($id);
        if (!is_array($updatedQuotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $updatedProjects = $projectModel
            ->where('quotation_id', $id)
            ->orderBy('id', 'ASC')
            ->findAll();

        $updatedQuotation = $this->formatQuotationForResponse($updatedQuotation, $customer);
        $updatedQuotation['projects'] = $this->formatProjectsForResponse($updatedProjects);
        $updatedQuotation['project_count'] = count($updatedProjects);

        $assignment = model(QuotationContractModel::class)->findByQuotationId($id);
        $updatedQuotation['contract_id'] = is_array($assignment) ? ((int) ($assignment['contract_id'] ?? 0) ?: null) : null;
        $updatedQuotation['quotation_contract_id'] = is_array($assignment) ? ((int) ($assignment['id'] ?? 0) ?: null) : null;

        return $this->res->ok($updatedQuotation, 'Quotation updated successfully');
    }

    public function index()
    {
        $params = $this->getListQueryParams();
        $customerId = (int) ($this->request->getGet('customer_id') ?? 0);
        $statusResult = $this->resolveStatusFilter($this->request->getGet('status'));
        if (is_array($statusResult) && isset($statusResult['error'])) {
            return $this->res->badRequest('Invalid quotation status filter.', [
                'status' => (string) $statusResult['error'],
            ]);
        }
        $status = is_string($statusResult) ? $statusResult : null;

        $result = $this->paginateFormattedQuotations($customerId > 0 ? $customerId : null, $params['search'], $params['perPage'], $params['offset'], $status);

        return $this->res->paginated($result['items'], $result['total'], $params['page'], $params['perPage'], 'Quotations retrieved successfully');
    }

    public function requested()
    {
        $params = $this->getListQueryParams();
        $result = $this->paginateFormattedQuotations(null, $params['search'], $params['perPage'], $params['offset'], self::STATUS_REQUESTED);

        return $this->res->paginated($result['items'], $result['total'], $params['page'], $params['perPage'], 'Requested quotations retrieved successfully');
    }

    public function show(int $id)
    {
        $quotationModel = new QuotationModel();
        $quotation = $quotationModel->find($id);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $customer = model(CustomerModel::class)->find((int) ($quotation['customer_id'] ?? 0));
        $quotation = $this->formatQuotationForResponse($quotation, is_array($customer) ? $customer : null);

        $projectModel = new ProjectModel();

        $projects = $projectModel
            ->where('quotation_id', $id)
            ->orderBy('id', 'ASC')
            ->findAll();

        $projects = $this->formatProjectsForResponse($projects);

        $quotation['projects'] = $projects;
        $quotation['project_count'] = count($projects);

        $assignment = model(QuotationContractModel::class)->findByQuotationId($id);
        $quotation['contract_id'] = is_array($assignment) ? ((int) ($assignment['contract_id'] ?? 0) ?: null) : null;
        $quotation['quotation_contract_id'] = is_array($assignment) ? ((int) ($assignment['id'] ?? 0) ?: null) : null;

        return $this->res->ok($quotation, 'Quotation retrieved successfully');
    }

    public function downloadPdf(int $id)
    {
        if (!class_exists(Mpdf::class)) {
            return $this->res->serverError('PDF library is not installed. Please install mpdf/mpdf.');
        }

        $quotationModel = new QuotationModel();
        $projectModel = new ProjectModel();

        $quotation = $quotationModel->find($id);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $customer = model(CustomerModel::class)->find((int) ($quotation['customer_id'] ?? 0));
        $quotation = $this->formatQuotationForResponse($quotation, is_array($customer) ? $customer : null);

        $projects = $projectModel
            ->where('quotation_id', $id)
            ->orderBy('id', 'ASC')
            ->findAll();
        $projects = $this->formatProjectsForResponse($projects);

        $tokenResult = $quotationModel->issuePublicResponseToken($id, self::PUBLIC_TOKEN_EXPIRY_DAYS);
        $plainToken = is_array($tokenResult) ? (string) ($tokenResult['token'] ?? '') : '';
        $acceptUrl = $plainToken !== '' ? $this->buildPublicQuotationActionUrl($plainToken, 'accept') : '';
        $rejectUrl = $plainToken !== '' ? $this->buildPublicQuotationActionUrl($plainToken, 'reject') : '';

        $quotationStatus = strtolower(trim((string) ($quotation['status'] ?? '')));
        $showActions = in_array($quotationStatus, self::CUSTOMER_ALLOWED_RESPONSE_STATUSES, true);

        $pdfHtml = $this->buildQuotationPdfHtml($quotation, $projects, [
            'acceptUrl' => $showActions ? $acceptUrl : '',
            'rejectUrl' => $showActions ? $rejectUrl : '',
            'publicToken' => $plainToken,
        ]);

        try {
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'tempDir' => WRITEPATH . 'cache',
            ]);
            $mpdf->WriteHTML($pdfHtml);
            $pdfBinary = $mpdf->Output('', Destination::STRING_RETURN);
        } catch (\Throwable $exception) {
            log_message('error', 'Quotation PDF generation failed. quotation_id={quotationId}, error={error}', [
                'quotationId' => $id,
                'error' => $exception->getMessage(),
            ]);

            return $this->res->serverError('Could not generate quotation PDF.');
        }

        $quoteNumber = trim((string) ($quotation['quote_number'] ?? ''));
        $fileName = $quoteNumber !== '' ? $quoteNumber : 'quotation-' . $id;
        $fileName = preg_replace('/[^A-Za-z0-9\-_]/', '-', $fileName) ?: ('quotation-' . $id);

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '.pdf"')
            ->setBody($pdfBinary);
    }

    public function byCustomer(int $customerId)
    {
        if ($customerId < 1) {
            return $this->res->badRequest('Valid customer id is required.');
        }

        $params = $this->getListQueryParams();
        $statusResult = $this->resolveStatusFilter($this->request->getGet('status'));
        if (is_array($statusResult) && isset($statusResult['error'])) {
            return $this->res->badRequest('Invalid quotation status filter.', [
                'status' => (string) $statusResult['error'],
            ]);
        }
        $status = is_string($statusResult) ? $statusResult : null;

        $result = $this->paginateFormattedQuotations($customerId, $params['search'], $params['perPage'], $params['offset'], $status);

        return $this->res->paginated($result['items'], $result['total'], $params['page'], $params['perPage'], 'Customer quotations retrieved successfully');
    }

    public function requestedByCustomer(int $customerId)
    {
        if ($customerId < 1) {
            return $this->res->badRequest('Valid customer id is required.');
        }

        $params = $this->getListQueryParams();
        $result = $this->paginateFormattedQuotations($customerId, $params['search'], $params['perPage'], $params['offset'], self::STATUS_REQUESTED);

        return $this->res->paginated($result['items'], $result['total'], $params['page'], $params['perPage'], 'Customer requested quotations retrieved successfully');
    }

    /**
     * @param array<int, array<string, mixed>> $projects
     * @return array<int, array<string, mixed>>
     */
    private function formatProjectsForResponse(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $projectIds = array_map(static fn(array $project): int => (int) ($project['id'] ?? 0), $projects);
        $projectIds = array_values(array_filter($projectIds, static fn(int $id): bool => $id > 0));

        $categoryIds = array_map(static fn(array $project): int => (int) ($project['category_id'] ?? 0), $projects);
        $categoryIds = array_values(array_unique(array_filter($categoryIds, static fn(int $id): bool => $id > 0)));

        $categoriesById = [];
        if ($categoryIds !== []) {
            $categoryRows = model(CategoryModel::class)->whereIn('id', $categoryIds)->findAll();
            foreach ($categoryRows as $category) {
                if (!is_array($category)) {
                    continue;
                }

                $categoryId = (int) ($category['id'] ?? 0);
                if ($categoryId > 0) {
                    $categoriesById[$categoryId] = trim((string) ($category['name'] ?? ''));
                }
            }
        }

        $projectServiceModel = new ProjectServiceModel();
        $servicesByProject = $projectServiceModel->getServiceNamesByProjectIds($projectIds);
        $serviceIdsByProject = $projectServiceModel->getServiceIdsByProjectIds($projectIds);

        foreach ($projects as &$project) {
            if (!is_array($project)) {
                continue;
            }

            $projectId = (int) ($project['id'] ?? 0);
            $categoryId = (int) ($project['category_id'] ?? 0);

            $project['category'] = $categoriesById[$categoryId] ?? '';
            $project['services'] = $servicesByProject[$projectId] ?? [];
            $project['service_ids'] = $serviceIdsByProject[$projectId] ?? [];
            $project['payment_type'] = $project['payment_type'];
            $project['hourly_hours'] = $project['hourly_hours'] ?? null;

            unset($project['nature'], $project['trades']);
        }
        unset($project);

        return $projects;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function extractProjectItems(array $data): array
    {
        $items = [];

        $projects = $data['projects'] ?? null;
        if (is_array($projects) && $projects !== []) {
            foreach ($projects as $project) {
                if (!is_array($project)) {
                    continue;
                }

                $items[] = $this->normalizeProjectItem($project);
            }

            return $items;
        }

        if (!$this->looksLikeProjectPayload($data)) {
            return [];
        }

        return [$this->normalizeProjectItem($data)];
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    private function normalizeProjectItem(array $project): array
    {
        return [
            'project_title' => trim((string) ($project['project_title'] ?? '')),
            'project_description' => trim((string) ($project['project_description'] ?? ($project['scope'] ?? ''))),
            'estimated_amount' => $this->normalizeMoneyValue($project['estimated_amount'] ?? ($project['estimatedAmount'] ?? ($project['amount'] ?? null))),
            'category_id' => $this->normalizeCategoryId($project),
            'service_ids' => $this->normalizeServiceIds($project['services'] ?? ($project['service_ids'] ?? [])),
            'payment_type' => $this->normalizePaymentType($project['payment_type'] ?? ($project['paymentType'] ?? 'fixed_rate')),
            'hourly_hours' => $this->normalizeDecimalValue($project['hourly_hours'] ?? ($project['hours'] ?? null)),
            'scope' => trim((string) ($project['scope'] ?? '')),
            'estimate_type' => trim((string) ($project['estimate_type'] ?? ($project['estimateType'] ?? ''))),
            'zip_code' => trim((string) ($project['zip_code'] ?? ($project['zipCode'] ?? ''))),
            'deadline' => trim((string) ($project['deadline'] ?? '')),
            'delivery_date' => $this->normalizeDateString($project['delivery_date'] ?? ($project['deliveryDate'] ?? null)),
            'deadline_date' => $this->normalizeDateString($project['deadline_date'] ?? ($project['deadlineDate'] ?? null)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeIncomingPayload(array $data): array
    {
        if (array_is_list($data)) {
            $normalized = [];
            $projects = [];

            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if ($this->looksLikeCustomerPayload($item)) {
                    $normalized = array_merge($normalized, $item);
                    continue;
                }

                $projects[] = $item;
            }

            if ($projects !== []) {
                $normalized['projects'] = $projects;
            }

            $data = $normalized;
        }

        $data['client_name'] = trim((string) ($data['client_name'] ?? ($data['name'] ?? '')));
        $data['client_email'] = trim((string) ($data['client_email'] ?? ($data['email'] ?? '')));
        $data['client_phone'] = trim((string) ($data['client_phone'] ?? ($data['phone'] ?? '')));
        $data['company'] = trim((string) ($data['company'] ?? ''));

        if (!isset($data['projects']) && $this->looksLikeProjectPayload($data)) {
            $data['projects'] = [$data];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validateNormalizedRequest(array $data): array
    {
        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $projectItems
     * @return array<string, string>
     */
    private function validateProjectItems(array $projectItems): array
    {
        $errors = [];

        foreach ($projectItems as $index => $item) {
            if ($item['deadline_date'] !== null && strtotime((string) $item['deadline_date']) === false) {
                $errors['projects.' . $index . '.deadlineDate'] = 'Deadline date must be a valid date.';
            }

            if ($item['delivery_date'] !== null && strtotime((string) $item['delivery_date']) === false) {
                $errors['projects.' . $index . '.deliveryDate'] = 'Delivery date must be a valid date.';
            }

            foreach ($this->validateProjectBillingItem($item, $index) as $field => $message) {
                $errors[$field] = $message;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, string>
     */
    private function validateProjectBillingItem(array $project, int $index): array
    {
        $errors = [];

        $paymentType = (string) ($project['payment_type'] ?? 'fixed_rate');
        if (!in_array($paymentType, ['fixed_rate', 'hourly'], true)) {
            $errors['projects.' . $index . '.payment_type'] = 'Payment type must be fixed_rate or hourly.';
        }

        if ($paymentType === 'hourly' && (!is_numeric($project['hourly_hours'] ?? null) || (float) $project['hourly_hours'] <= 0)) {
            $errors['projects.' . $index . '.hourly_hours'] = 'Hourly payment requires a valid hours value greater than 0.';
        }

        if ($project['estimated_amount'] !== null && $project['estimated_amount'] !== '' && !is_numeric($project['estimated_amount'])) {
            $errors['projects.' . $index . '.estimated_amount'] = 'Amount must be numeric.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function looksLikeProjectPayload(array $item): bool
    {
        return isset($item['project_title'])
            || isset($item['category'])
            || isset($item['category_id'])
            || isset($item['services']);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function looksLikeCustomerPayload(array $item): bool
    {
        return isset($item['name']) || isset($item['email']) || isset($item['phone']) || isset($item['client_name']);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function normalizeCategoryId(array $item): int
    {
        if (isset($item['category_id'])) {
            $id = (int) $item['category_id'];
            if ($id > 0) {
                return $id;
            }
        }

        if (isset($item['category']) && is_numeric($item['category'])) {
            $id = (int) $item['category'];
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * @return array<int, int>
     */
    private function normalizeServiceIds(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/\s*,\s*/', $value) ?: [];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $items = array_map('intval', $value);
        $items = array_values(array_unique(array_filter($items, static fn(int $id): bool => $id > 0)));

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $projectItems
     * @return array{projects:array<int, array<string, mixed>>,errors:array<string, string>}
     */
    private function resolveProjectTaxonomy(array $projectItems): array
    {
        $categoryModel = new CategoryModel();
        $serviceModel = new ServiceModel();

        $categories = $categoryModel->findAll();
        $categoryById = [];
        $categoryBySlug = [];
        $categoryByName = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }

            $id = (int) ($category['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $categoryById[$id] = $category;
            $slug = strtolower(trim((string) ($category['slug'] ?? '')));
            if ($slug !== '') {
                $categoryBySlug[$slug] = $category;
            }

            $name = strtolower(trim((string) ($category['name'] ?? '')));
            if ($name !== '') {
                $categoryByName[$name] = $category;
            }
        }

        $services = $serviceModel->withCategories();
        $serviceById = [];
        $serviceBySlug = [];
        $serviceByName = [];
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }

            $id = (int) ($service['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $serviceById[$id] = $service;
            $slug = strtolower(trim((string) ($service['slug'] ?? '')));
            if ($slug !== '') {
                $serviceBySlug[$slug] = $service;
            }

            $name = strtolower(trim((string) ($service['name'] ?? '')));
            if ($name !== '') {
                $serviceByName[$name] = $service;
            }
        }

        $errors = [];
        $resolved = [];

        foreach ($projectItems as $index => $item) {
            $categoryId = (int) ($item['category_id'] ?? 0);
            if ($categoryId < 1) {
                $errors['projects.' . $index . '.category'] = 'Category is required for each project.';
                continue;
            }

            $category = $categoryById[$categoryId] ?? null;

            if (!is_array($category)) {
                $errors['projects.' . $index . '.category'] = 'Category was not found.';
                continue;
            }

            $rawServices = $this->normalizeServiceIds($item['service_ids'] ?? []);
            if ($rawServices === []) {
                $errors['projects.' . $index . '.services'] = 'At least one service is required for each project.';
                continue;
            }

            $serviceIds = [];
            $serviceNames = [];
            $invalid = [];

            foreach ($rawServices as $rawService) {
                $service = null;

                $service = $serviceById[(int) $rawService] ?? null;

                if (!is_array($service)) {
                    $invalid[] = (string) $rawService;
                    continue;
                }

                $serviceCategoryIds = array_map(static fn(array $categoryRow): int => (int) ($categoryRow['id'] ?? 0), is_array($service['categories'] ?? null) ? $service['categories'] : []);
                if (!in_array($categoryId, $serviceCategoryIds, true)) {
                    $invalid[] = (string) $rawService;
                    continue;
                }

                $serviceIds[] = (int) ($service['id'] ?? 0);
                $serviceName = trim((string) ($service['name'] ?? ''));
                if ($serviceName !== '') {
                    $serviceNames[] = $serviceName;
                }
            }

            if ($invalid !== []) {
                $errors['projects.' . $index . '.services'] = 'Invalid service(s) for selected category: ' . implode(', ', $invalid);
                continue;
            }

            $item['category'] = trim((string) ($category['name'] ?? ''));
            $item['services'] = array_values(array_unique($serviceNames));
            $item['category_id'] = $categoryId;
            $item['service_ids'] = array_values(array_unique(array_filter($serviceIds, static fn(int $id): bool => $id > 0)));

            $resolved[] = $item;
        }

        return [
            'projects' => $errors === [] ? $resolved : $projectItems,
            'errors' => $errors,
        ];
    }

    private function normalizePaymentType(mixed $value): string
    {
        $paymentType = strtolower(trim((string) $value));

        if (in_array($paymentType, ['hourly', 'hourly_rate', 'hourly-rate', 'hourlyrate'], true)) {
            return 'hourly';
        }

        if (in_array($paymentType, ['fixed_rate', 'fixed', 'fixed-rate', 'fixedrate'], true)) {
            return 'fixed_rate';
        }

        return 'fixed_rate';
    }

    private function normalizeDiscountType(mixed $value): ?string
    {
        $discountType = strtolower(trim((string) $value));
        if ($discountType === 'fixed') {
            return 'fixed_amount';
        }

        return in_array($discountType, ['fixed_amount', 'percentage'], true) ? $discountType : null;
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        return $text === '' ? null : $text;
    }

    /**
     * @param array<string, mixed> $quotation
     * @param array<int, array<string, mixed>> $projects
     */
    private function buildQuotationPdfHtml(array $quotation, array $projects, array $options = []): string
    {
        $quotationId = (int) ($quotation['id'] ?? 0);
        $assignment = $quotationId > 0 ? model(QuotationContractModel::class)->findByQuotationId($quotationId) : null;
        $contractName = null;

        if (is_array($assignment)) {
            $contract = (new ContractModel())->findDetailed((int) ($assignment['contract_id'] ?? 0));
            if (is_array($contract)) {
                $contractName = $this->normalizeNullableText($contract['contract_name'] ?? null);
            }
        }

        $acceptUrl = trim((string) ($options['acceptUrl'] ?? ''));
        $rejectUrl = trim((string) ($options['rejectUrl'] ?? ''));
        $publicToken = trim((string) ($options['publicToken'] ?? ''));

        return '<html><head><meta charset="utf-8"><title>Quotation PDF</title><style>body, table, td, div, span, p, a, strong { font-family: Arial, Helvetica, sans-serif !important; }</style></head><body style="margin:0;padding:0;background:#ffffff;">'
            . $this->buildQuotationDocumentHtml($quotation, $projects, [
                'showActions' => true,
                'acceptUrl' => $acceptUrl,
                'rejectUrl' => $rejectUrl,
                'contractName' => $contractName,
                'publicToken' => $publicToken,
            ])
            . '</body></html>';
    }

    /**
     * @param array<string, mixed> $quotation
     * @param array<int, array<string, mixed>> $projects
     * @param array<string, mixed> $options
     */
    private function buildQuotationDocumentHtml(array $quotation, array $projects, array $options = []): string
    {
        $quoteNumber = trim((string) ($quotation['quote_number'] ?? ''));
        $description = trim((string) ($quotation['description'] ?? ''));
        $createdAt = trim((string) ($quotation['created_at'] ?? ''));
        $expiryLabel = trim((string) ($options['expiryLabel'] ?? ''));
        $quoteTitle = $quoteNumber !== '' ? $quoteNumber : ('#' . (int) ($quotation['id'] ?? 0));
        $quoteDateLabel = $createdAt !== '' ? date('M d, Y', strtotime($createdAt) ?: time()) : date('M d, Y');

        $showActions = (bool) ($options['showActions'] ?? false);
        $quotationStatus = trim((string) ($options['quotationStatus'] ?? ''));
        $allowedToRespond = $quotationStatus === '' || in_array($quotationStatus, self::CUSTOMER_ALLOWED_RESPONSE_STATUSES, true);
        $showActions = $showActions && $allowedToRespond;
        $acceptUrl = trim((string) ($options['acceptUrl'] ?? ''));
        $rejectUrl = trim((string) ($options['rejectUrl'] ?? ''));
        $contractUrl = trim((string) ($options['contractUrl'] ?? ''));
        $publicToken = trim((string) ($options['publicToken'] ?? ''));
        $contractName = trim((string) ($options['contractName'] ?? ''));

        if ($contractUrl === '' && $publicToken !== '') {
            $contractUrl = $this->buildPublicContractPreviewUrl($publicToken);
        }

        $customer = is_array($quotation['customer'] ?? null) ? $quotation['customer'] : [];
        $customerName = trim((string) ($customer['name'] ?? 'N/A'));
        $customerEmail = trim((string) ($customer['email'] ?? 'N/A'));
        $customerPhone = trim((string) ($customer['phone'] ?? 'N/A'));
        $customerCompany = trim((string) ($customer['company'] ?? 'N/A'));

        $logoDataUri = $this->getLogoDataUri();
        $totals = $this->calculateQuotationTotals($quotation, $projects);

        $business = is_array($quotation['business'] ?? null) ? $quotation['business'] : [];
        $companyName = trim((string) ($business['company_name'] ?? ($quotation['business_name'] ?? 'Remote Estimation LLC')));
        if ($companyName === '') {
            $companyName = 'Remote Estimation LLC';
        }

        $adminName = trim((string) ($business['admin_name'] ?? ($quotation['business_admin_name'] ?? '')));
        $businessEmail = trim((string) ($business['email'] ?? ($quotation['business_email'] ?? 'info@apexestimating.com')));
        $businessPhone = trim((string) ($business['phone'] ?? ($quotation['business_phone'] ?? '(214) 555-0183')));
        $businessAddress = trim((string) ($business['address'] ?? ($quotation['business_address'] ?? '4820 Commerce Drive, Suite 310, Dallas, TX 75201')));
        $businessWebsite = trim((string) ($business['website_url'] ?? ($quotation['business_website_url'] ?? 'www.apexestimating.com')));

        $companyTagline = 'Precision Takeoffs - Complete Cost Estimates';
        $companyAddressLine1 = $businessAddress;
        $companyAddressLine2 = '';
        $companyContactParts = [];
        if ($businessPhone !== '') {
            $companyContactParts[] = $businessPhone;
        }
        if ($businessEmail !== '') {
            $companyContactParts[] = $businessEmail;
        }
        $companyContact = $companyContactParts !== [] ? implode(' - ', $companyContactParts) : '';
        $companyWebsite = $businessWebsite;

        $companyAddressBlock = esc($companyAddressLine1);
        if ($companyAddressLine2 !== '') {
            $companyAddressBlock .= '<br>' . esc($companyAddressLine2);
        }

        $companyAddressInline = esc($companyAddressLine1);
        if ($companyAddressLine2 !== '') {
            $companyAddressInline .= ', ' . esc($companyAddressLine2);
        }

        $companyAdminHtml = $adminName !== ''
            ? '<div style="font-size:13px;line-height:1.6;color:#475569;">Admin: ' . esc($adminName) . '</div>'
            : '';

        $projectsHtml = '';
        $summaryLines = '';
        $projectIndex = 0;

        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }

            $projectIndex++;

            $services = is_array($project['services'] ?? null) ? $project['services'] : [];
            $lineTotal = $this->calculateProjectLineTotal($project);
            $amount = $this->formatCurrency($lineTotal);

            $projectTitle = trim((string) ($project['project_title'] ?? 'Project'));
            $categoryName = trim((string) ($project['category'] ?? 'General Scope'));
            $projectDescription = trim((string) ($project['project_description'] ?? ''));
            $estimateType = trim((string) ($project['estimate_type'] ?? 'Detailed Estimate'));
            $deadlineDate = trim((string) ($project['deadline_date'] ?? ($project['delivery_date'] ?? ($project['deadline'] ?? ''))));
            $projectDateLabel = $deadlineDate !== '' ? date('M j, Y', strtotime($deadlineDate) ?: time()) : $quoteDateLabel;
            $paymentType = strtolower(trim((string) ($project['payment_type'] ?? 'fixed_rate')));
            $hours = $this->toFloat($project['hourly_hours'] ?? 0);
            $rate = $this->toFloat($project['hourly_rate'] ?? ($project['estimated_amount'] ?? 0));

            $pricingMeta = 'Fixed price';
            if ($paymentType === 'hourly') {
                if ($hours > 0) {
                    $pricingMeta = number_format($hours, 0) . ' hrs x ' . $this->formatCurrency($rate) . '/hr = ' . $amount;
                } else {
                    $pricingMeta = 'Hourly rate';
                }
            }

            $serviceBadges = '';
            foreach ($services as $service) {
                $serviceText = trim((string) $service);
                if ($serviceText === '') {
                    continue;
                }

                // Add a trailing non-breaking space so badge separation survives strict email/PDF renderers.
                $serviceBadges .= '<span style="display:inline-block;padding:5px 10px;margin:8px 8px 8px 8px !important;border-radius:8px;border:1px solid #f2c6b9;background:#fff4ef;color:#cc5b37;font-size:12px;line-height:1.2;font-weight:600;white-space:nowrap;">' . esc($serviceText) . '</span>&nbsp;';
            }

            if ($serviceBadges === '') {
                $serviceBadges = '<span style="display:inline-block;padding:5px 10px;border-radius:8px;border:1px solid #d1d5db;background:#f9fafb;color:#6b7280;font-size:12px;line-height:1.2;">No tagged services</span>';
            }

            $projectMetaLine = esc($estimateType) . '  -  #' . esc((string) ((int) ($project['id'] ?? $projectIndex))) . '  -  ' . esc($projectDateLabel);
            $projectBlurb = $projectDescription !== ''
                ? esc($projectDescription)
                : 'Scope details are included in this project estimate package.';

            $projectsHtml .= '
                <tr style="border:1px solid #707e92 !important;border-radius:8px !important;">
                <td style="padding:0 0 16px 0;">

                    <table width="100%" cellspacing="0">
                    
                    <!-- TOP ROW -->
                    <tr>
                        <td style="padding:16px;">

                        <table width="100%">
                            <tr>

                            <!-- INDEX -->
                            <td width="40" valign="top">
                                <div style="width:26px;height:26px;border-radius:6px;background:#f1f5f9;text-align:center;line-height:26px;font-size:12px;font-weight:bold;color:#334155;">
                                ' . $projectIndex . '
                                </div>
                            </td>

                            <!-- CONTENT -->
                            <td valign="top">

                                <div style="font-size:15px;font-weight:700;color:#0f172a;">
                                ' . esc($projectTitle) . '
                                </div>

                                <div style="font-size:12px;color:#64748b;line-height:1.4;margin:6px 0 10px 0 !important; padding-bottom:2px;">
                                ' . $projectMetaLine . '
                                </div>

                                <div style="margin:0 0 10px 0;line-height:1;">
                                ' . $serviceBadges . '
                                </div>

                                <div style="font-size:12px;color:#6b7280; margin-top:6px !important;">
                                Category: ' . esc($categoryName) . '
                                </div>

                            </td>

                            <!-- PRICE -->
                            <td width="140" align="right" valign="top">

                                <div style="font-size:18px;font-weight:700;line-height:1.2;color:#111827;">
                                ' . esc($amount) . '
                                </div>

                                <div style="font-size:12px;line-height:1.4;color:#6b7280;margin-top:6px;">
                                ' . esc($pricingMeta) . '
                                </div>

                            </td>

                            </tr>
                        </table>

                        </td>
                    </tr>

                    <!-- DESCRIPTION ROW -->
                    <tr>
                        <td style="padding:12px 18px 16px 18px;border-top:1px solid #f1f5f9;">

                        <div style="font-size:12px;line-height:1.7;color:#475569;">
                            ' . $projectBlurb . '
                        </div>

                        </td>
                    </tr>

                    </table>

                </td>
                </tr>';

            $summaryLines .= '<tr>'
                . '<td style="padding:4px 0;color:#516173;font-size:13px;line-height:1.4;">Project ' . $projectIndex . ' - ' . esc($projectTitle) . '</td>'
                . '<td style="padding:4px 0;text-align:right;color:#334155;font-size:13px;line-height:1.4;">' . esc($amount) . '</td>'
                . '</tr>';
        }

        if ($projectsHtml === '') {
            $projectsHtml = '<tr><td style="padding:20px;text-align:center;font-size:13px;line-height:1.5;color:#6b7280;">No projects found for this quotation.</td></tr>';
            $summaryLines = '<tr><td style="padding:4px 0;color:#64748b;font-size:13px;line-height:1.4;">No project lines available</td><td style="padding:4px 0;text-align:right;color:#64748b;font-size:13px;line-height:1.4;">' . esc($this->formatCurrency(0.0)) . '</td></tr>';
        }

        $discountLabel = 'Discount';
        if (($totals['discount_type'] ?? '') === 'percentage') {
            $discountLabel = 'Discount (Percentage - ' . $this->toFloat($totals['discount_value'] ?? 0) . '%)';
        } elseif (($totals['discount_type'] ?? '') === 'fixed_amount') {
            $discountLabel = 'Discount (Fixed Amount)';
        }

        $actionsHtml = '';
        if ($showActions && $acceptUrl !== '' && $rejectUrl !== '') {
            $actionsHtml .= '<div style="margin:0 0 24px 0;padding:16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;">';
            $actionsHtml .= '<div style="font-size:14px;color:#1e3a8a;font-weight:600;margin-bottom:10px;">Please review and respond using the buttons below.</div>';
            $actionsHtml .= '<a href="' . esc($acceptUrl) . '" style="display:inline-block;padding:10px 18px;background:#047857;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:700;margin-right:8px;">Accept Quotation</a>';
            $actionsHtml .= '<a href="' . esc($rejectUrl) . '" style="display:inline-block;padding:10px 18px;background:#b91c1c;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:700;">Reject Quotation</a>';
            $actionsHtml .= '</div>';
        }

        $contractHtml = '';
        if ($contractUrl !== '' || $contractName !== '') {
            $contractTitle = $contractName !== '' ? $contractName : 'Contract';

            $contractHtml .= '<div style="margin:28px 0 0 0;padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;">';
            $contractHtml .= '<table width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;"><tr><td valign="top">';
            $contractHtml .= '<div style="font-size:16px;font-weight:700;color:#111827;margin:0 0 6px 0;">' . esc($contractTitle) . '</div>';
            $contractHtml .= '<div style="font-size:13px;color:#4b5563;margin:0 0 10px 0;">Open contract preview and sign digitally.</div>';
            $contractHtml .= '</td><td align="right" valign="middle" style="padding-left:16px;white-space:nowrap;">';
            if ($contractUrl !== '') {
                $contractHtml .= '<a href="' . esc($contractUrl) . '" style="display:inline-block;padding:9px 14px;background:#1f2937;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">Open Contract Link</a>';
            }
            $contractHtml .= '</td></tr></table></div>';
        }

        $expiryHtml = $expiryLabel !== ''
            ? '<div style="font-size:12px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;margin:0 0 14px 0;">Public response link expires on ' . esc($expiryLabel) . '.</div>'
            : '';

        $headerLogoHtml = '';
        if ($logoDataUri !== null && $logoDataUri !== '') {
            $headerLogoHtml = '<div style="margin:16px;line-height:0;" class="logo"><img src="' . esc($logoDataUri) . '" alt="' . esc($companyName) . '" style="display:block;max-width:190px;width:190px;height:auto;"></div>';
        }

        return '<style type="text/css">.quotation-doc, .quotation-doc * { font-family: Arial, Helvetica, sans-serif !important; } .quotation-doc table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; } ,logo{margin-bottom:16px !important;}</style>
        
        <div class="quotation-doc" style="font-family: Arial, Helvetica, sans-serif; background:#ffffff; padding:0; margin:0;">

        <div style="max-width:1000px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">

        <!-- HEADER -->
        <div style="background:#c2022c;padding:22px 24px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        <tr>
        <td valign="top" style="padding:0;">
        ' . $headerLogoHtml . '

        <div style="font-size:13px;line-height:1.35;opacity:.95;color:#ffffff;margin-top:16px !important;">' . esc($companyTagline) . '</div>

        <div style="margin-top:12px;font-size:12px;line-height:1.45;color:#ffffff;">
        ' . $companyAddressBlock . '<br>
        ' . esc($companyContact) . '
        </div>
        </td>

        <td align="right" valign="top" style="padding:0 0 0 14px;">
        <div style="font-size:20px;line-height:1.1;font-weight:700;color:#ffffff;">QUOTATION</div>
        <div style="font-size:15px;line-height:1.4;margin-top:6px;color:#ffffff;">' . esc($quoteTitle) . '</div>
        <div style="margin-top:10px;font-size:12px;line-height:1.4;color:#ffffff;">
        <strong>Date:</strong> ' . esc($quoteDateLabel) . '
        </div>
        </td>
        </tr>
        </table>
        </div>

        <!-- BODY -->
        <div style="padding:22px 24px;">

        ' . $expiryHtml . '
        ' . $actionsHtml . '

        <!-- BILL TO -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;border-collapse:collapse;">
        <tr>
        <td width="50%" valign="top" style="padding-right:12px;">
        <div style="font-size:12px;letter-spacing:0.05em;color:#6b7280;margin-bottom:8px;">BILL TO</div>
        <div style="font-size:18px;line-height:1.3;font-weight:700;color:#0f172a;">' . esc($customerName) . '</div>
        <div style="font-size:13px;line-height:1.6;color:#475569;">' . esc($customerCompany) . '</div>
        <div style="font-size:13px;line-height:1.6;color:#475569;">' . esc($customerEmail) . '</div>
        <div style="font-size:13px;line-height:1.6;color:#475569;">' . esc($customerPhone) . '</div>
        </td>

        <td width="50%" valign="top" style="padding-left:12px;">
        <div style="font-size:12px;letter-spacing:0.05em;color:#6b7280;margin-bottom:8px;">FROM</div>
        <div style="font-size:16px;line-height:1.3;font-weight:700;color:#0f172a;">' . esc($companyName) . '</div>
        ' . $companyAdminHtml . '
        <div style="font-size:13px;line-height:1.6;color:#475569;">' . $companyAddressInline . '</div>
        <div style="font-size:13px;line-height:1.6;color:#475569;">' . esc($companyWebsite) . '</div>
        </td>
        </tr>
        </table>

        <!-- DESCRIPTION -->
        <div style="margin-bottom:24px;">
        <div style="font-size:12px;letter-spacing:0.05em;color:#6b7280;margin-bottom:8px;">DESCRIPTION</div>
        <div style="font-size:14px;line-height:1.7;color:#0f172a;">
        ' . esc($description !== '' ? $description : 'Complete estimation package prepared for your review.') . '
        </div>
        </div>

        <!-- PROJECTS -->
        <div style="margin-bottom:24px;">
        <div style="font-size:12px;letter-spacing:0.05em;color:#6b7280;margin-bottom:12px;">PROJECTS</div>

        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
        '
            . $projectsHtml .
            '</table>'
            . '</div>' . 
            '<div style="margin-top:30px;padding-top:12px;border-top:1px solid #e5e7eb;">' . '<table width="100%" cellspacing="0" cellpadding="0">' 
            . '<tr>' 
            . '<td style="vertical-align:top;width:56%;padding-right:18px;">' 
            . '<div style="font-size:20px;text-transform:uppercase;letter-spacing:0.06em;color:#516173;margin-bottom:10px;line-height:1.1;">Financial Summary</div>' 
            . '</td>' . 
            '<td style="vertical-align:top;width:44%;">' . 
            '<table width="100%" cellspacing="0" cellpadding="0">' . $summaryLines . 
            '<tr><td colspan="2" style="padding:8px 0;border-top:1px solid #d1d5db;"></td></tr>' . 
            '<tr>
            <td style="padding:6px 0;color:#111827;font-size:13px;line-height:1.4;">Subtotal</td>
            <td style="padding:6px 0;text-align:right;font-weight:700;font-size:13px;line-height:1.4;">' . esc($this->formatCurrency($totals['subtotal'])) . '</td>
            
            </tr>' . '<tr><td style="padding:6px 0;color:#059669;font-size:13px;line-height:1.4;">'
             . esc($discountLabel) . 
             '</td><td style="padding:6px 0;text-align:right;font-weight:700;color:#059669;font-size:13px;line-height:1.4;">- ' 
             . esc($this->formatCurrency($totals['discount_amount'])) . '</td></tr>' . 
             '<tr>
             <td colspan="2" style="padding:8px 0;border-top:1px solid #d1d5db;">
             </td>
             </tr>' . 
             '<tr>
             <td style="padding:8px 0;font-size:39px;color:#ffffff;display:none;">.
             </td>
             <td>
             </td>
             </tr>' 
             . '<tr>
             <td style="padding:6px 0;font-size:20px;font-weight:700;color:#111827;line-height:1.2;">Total</td>
             <td style="padding:0;text-align:right;font-size:34px;font-weight:700;color:#111827;line-height:1.1;">' . esc($this->formatCurrency($totals['total'])) . '</td>
             </tr>' . 
             '</table>' . 
             '</td>' . 
             '</tr>' . 
             '</table>' . 
             '</div>' . $contractHtml . '</div>' . '</div>' . '</div>';
    }

    /**
     * @param array<string, mixed> $quotation
     * @param array<int, array<string, mixed>> $projects
     * @return array{subtotal:float, discount_amount:float, total:float, discount_type:string, discount_value:float}
     */
    private function calculateQuotationTotals(array $quotation, array $projects): array
    {
        $subtotal = 0.0;
        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }

            $subtotal += $this->calculateProjectLineTotal($project);
        }

        $discountType = (string) ($quotation['discount_type'] ?? '');
        $discountValue = $this->toFloat($quotation['discount_value'] ?? 0);
        $discountAmount = 0.0;

        if ($discountType === 'percentage') {
            $discountAmount = $subtotal * ($discountValue / 100);
        } elseif ($discountType === 'fixed_amount') {
            $discountAmount = $discountValue;
        }

        $discountAmount = min(max(0.0, $discountAmount), $subtotal);
        $total = max(0.0, $subtotal - $discountAmount);

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'total' => round($total, 2),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
        ];
    }

    /**
     * @param array<string, mixed> $project
     */
    private function calculateProjectLineTotal(array $project): float
    {
        $baseAmount = $this->toFloat($project['estimated_amount'] ?? 0);
        $paymentType = strtolower(trim((string) ($project['payment_type'] ?? 'fixed_rate')));

        if ($paymentType !== 'hourly') {
            return round($baseAmount, 2);
        }

        $hours = $this->toFloat($project['hourly_hours'] ?? 0);
        if ($hours <= 0) {
            return round($baseAmount, 2);
        }

        return round($baseAmount * $hours, 2);
    }

    private function formatCurrency(float $amount): string
    {
        return '$' . number_format($amount, 2, '.', ',');
    }

    private function toFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return 0.0;
        }

        return (float) preg_replace('/[^0-9.\-]/', '', $value);
    }

    private function getLogoDataUri(): ?string
    {
        $logoPath = FCPATH . 'assets/images/white-logo.png';
        if (!is_file($logoPath)) {
            $logoPath = FCPATH . 'assets/images/logo.png';
        }

        if (!is_file($logoPath)) {
            return null;
        }

        $logoContents = file_get_contents($logoPath);
        if ($logoContents === false) {
            return null;
        }

        $mimeType = mime_content_type($logoPath) ?: 'image/png';

        return 'data:' . $mimeType . ';base64,' . base64_encode($logoContents);
    }

    /**
     * @param array<string, mixed> $quotation
     * @param array<string, mixed>|null $customer
     * @return array<string, mixed>
     */
    private function formatQuotationForResponse(array $quotation, ?array $customer = null): array
    {
        if ($customer === null) {
            $customer = [
                'id' => $quotation['customer_ref_id'] ?? $quotation['customer_id'] ?? null,
                'name' => $quotation['customer_name'] ?? null,
                'email' => $quotation['customer_email'] ?? null,
                'phone' => $quotation['customer_phone'] ?? null,
                'company' => $quotation['customer_company'] ?? null,
            ];
        }

        $quotation['customer'] = [
            'id' => (int) ($customer['id'] ?? 0) ?: null,
            'name' => $this->normalizeNullableText($customer['name'] ?? null),
            'email' => $this->normalizeNullableText($customer['email'] ?? null),
            'phone' => $this->normalizeNullableText($customer['phone'] ?? null),
            'company' => $this->normalizeNullableText($customer['company'] ?? null),
        ];

        $quotation['business'] = [
            'profile_id' => (int) ($quotation['business_profile_id'] ?? 0) ?: null,
            'company_name' => $this->normalizeNullableText($quotation['business_name'] ?? null),
            'admin_name' => $this->normalizeNullableText($quotation['business_admin_name'] ?? null),
            'email' => $this->normalizeNullableText($quotation['business_email'] ?? null),
            'phone' => $this->normalizeNullableText($quotation['business_phone'] ?? null),
            'address' => $this->normalizeNullableText($quotation['business_address'] ?? null),
            'website_url' => $this->normalizeNullableText($quotation['business_website_url'] ?? null),
        ];

        unset($quotation['title']);
        unset($quotation['public_response_token_hash'], $quotation['public_response_token_issued_at'], $quotation['public_response_token_expires_at'], $quotation['public_response_token_used_at']);
        unset($quotation['customer_ref_id'], $quotation['customer_name'], $quotation['customer_email'], $quotation['customer_phone'], $quotation['customer_company']);
        unset($quotation['business_profile_id'], $quotation['business_name'], $quotation['business_admin_name'], $quotation['business_email'], $quotation['business_phone'], $quotation['business_address'], $quotation['business_website_url']);

        return $quotation;
    }

    /**
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    private function paginateFormattedQuotations(?int $customerId, string $search, int $perPage, int $offset, ?string $status): array
    {
        $quotationModel = new QuotationModel();
        $result = $quotationModel->paginateQuotations($customerId, $search, $perPage, $offset, $status);
        $result['items'] = $this->attachQuotationContractIds($result['items']);
        $result['items'] = array_map(fn(array $quotation): array => $this->formatQuotationForResponse($quotation), $result['items']);

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $quotations
     * @return array<int, array<string, mixed>>
     */
    private function attachQuotationContractIds(array $quotations): array
    {
        if ($quotations === []) {
            return [];
        }

        $quotationIds = array_map(static fn(array $quotation): int => (int) ($quotation['id'] ?? 0), $quotations);
        $quotationIds = array_values(array_unique(array_filter($quotationIds, static fn(int $id): bool => $id > 0)));
        if ($quotationIds === []) {
            return $quotations;
        }

        $rows = model(QuotationContractModel::class)
            ->select('id, quotation_id')
            ->whereIn('quotation_id', $quotationIds)
            ->orderBy('id', 'DESC')
            ->findAll();

        $contractIdByQuotationId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $quotationId = (int) ($row['quotation_id'] ?? 0);
            $contractId = (int) ($row['id'] ?? 0);
            if ($quotationId < 1 || $contractId < 1 || isset($contractIdByQuotationId[$quotationId])) {
                continue;
            }

            $contractIdByQuotationId[$quotationId] = $contractId;
        }

        foreach ($quotations as &$quotation) {
            if (!is_array($quotation)) {
                continue;
            }

            $quotationId = (int) ($quotation['id'] ?? 0);
            $quotation['quotation_contract_id'] = $contractIdByQuotationId[$quotationId] ?? null;
        }
        unset($quotation);

        return $quotations;
    }

    /**
     * @return string|array{error:string}|null
     */
    private function resolveStatusFilter(mixed $status)
    {
        if ($status === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $status));
        if ($normalized === '') {
            return null;
        }

        if (!in_array($normalized, self::ALLOWED_STATUSES, true)) {
            return [
                'error' => 'Allowed values: ' . implode(', ', self::ALLOWED_STATUSES) . '.',
            ];
        }

        return $normalized;
    }

    private function resolveDecisionStatus(mixed $decision): ?string
    {
        $normalized = strtolower(trim((string) $decision));
        if (in_array($normalized, ['accept', self::STATUS_ACCEPTED], true)) {
            return self::STATUS_ACCEPTED;
        }

        if (in_array($normalized, ['reject', self::STATUS_REJECTED], true)) {
            return self::STATUS_REJECTED;
        }

        return null;
    }

    private function isTerminalStatus(string $status): bool
    {
        $normalized = strtolower(trim($status));
        return in_array($normalized, [self::STATUS_ACCEPTED, self::STATUS_REJECTED], true);
    }

    /**
     * @param array<string, mixed> $quotation
     */
    private function isPublicTokenActive(array $quotation): bool
    {
        $usedAt = trim((string) ($quotation['public_response_token_used_at'] ?? ''));
        if ($usedAt !== '') {
            return false;
        }

        $expiresAt = trim((string) ($quotation['public_response_token_expires_at'] ?? ''));
        if ($expiresAt === '') {
            return false;
        }

        $expiresTs = strtotime($expiresAt);
        if ($expiresTs === false) {
            return false;
        }

        return $expiresTs > time();
    }

    /**
     * @param array<string, mixed> $quotation
     * @return array<string, mixed>
     */
    private function sanitizeQuotationForPublicResponse(array $quotation): array
    {
        unset($quotation['source_request_id'], $quotation['notes'], $quotation['square_order_id'], $quotation['square_invoice_id'], $quotation['square_status'], $quotation['square_error'], $quotation['square_synced_at']);

        return $quotation;
    }

    private function buildPublicQuotationPreviewUrl(string $token): string
    {
        return $this->buildFrontendUrl('/quotation-preview', [
            'token' => $token,
        ]);
    }

    private function buildPublicQuotationActionUrl(string $token, string $action): string
    {
        return $this->buildFrontendUrl('/quotation-preview', [
            'token' => $token,
            'action' => strtolower(trim($action)),
        ]);
    }

    /**
     * @param array<string, mixed> $quotation
     * @return array{owner_name:?string,owner_signature:?string,owner_signed_at:?string}
     */
    private function buildQuotationContractBusinessPayload(array $quotation): array
    {
        $ownerName = $this->normalizeNullableText($quotation['business_admin_name'] ?? null);
        if ($ownerName === null) {
            $ownerName = $this->normalizeNullableText($quotation['business_admin_name'] ?? null);
        }

        return [
            'owner_name' => $ownerName,
            'owner_signature' => $ownerName,
            'owner_signed_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function buildPublicContractPreviewUrl(string $token): string
    {
        return $this->buildFrontendUrl('/contract-preview', [
            'token' => $token,
        ]);
    }

    /**
     * @param array<string, string> $query
     */
    private function buildFrontendUrl(string $path, array $query = []): string
    {
        $frontendUrl = trim((string) getenv('app.FrontendURL'));
        if ($frontendUrl === '') {
            $frontendUrl = trim((string) getenv('APP_URL'));
        }
        if ($frontendUrl === '') {
            $frontendUrl = rtrim((string) base_url(), '/');
        }

        $url = rtrim($frontendUrl, '/') . '/' . ltrim($path, '/');
        if ($query === []) {
            return $url;
        }

        return $url . '?' . http_build_query($query);
    }

    private function formatDateTimeForEmail(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $value;
        }

        return date('M d, Y H:i', $timestamp);
    }

    private function maskEmail(string $email): string
    {
        $normalized = trim($email);
        if ($normalized === '' || !str_contains($normalized, '@')) {
            return '';
        }

        [$local, $domain] = explode('@', $normalized, 2);
        $local = trim($local);
        if ($local === '') {
            return '***@' . $domain;
        }

        $prefix = substr($local, 0, 1);
        return $prefix . str_repeat('*', max(2, strlen($local) - 1)) . '@' . $domain;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveBusinessSnapshotPayload(array $data, bool $strictSelection = false): array
    {
        $businessProfileModel = new BusinessProfileModel();
        $profile = $businessProfileModel->findActive();

        if (!is_array($profile)) {
            return [
                'business_profile_id' => null,
                'business_name' => 'Remote Estimation LLC',
                'business_admin_name' => null,
                'business_email' => 'info@apexestimating.com',
                'business_phone' => '(214) 555-0183',
                'business_address' => '4820 Commerce Drive, Suite 310, Dallas, TX 75201',
                'business_website_url' => 'www.apexestimating.com',
            ];
        }

        return [
            'business_profile_id' => (int) ($profile['id'] ?? 0) ?: null,
            'business_name' => $this->normalizeNullableText($profile['company_name'] ?? null),
            'business_admin_name' => $this->normalizeNullableText($profile['admin_name'] ?? null),
            'business_email' => $this->normalizeNullableText($profile['email'] ?? null),
            'business_phone' => $this->normalizeNullableText($profile['phone'] ?? null),
            'business_address' => $this->normalizeNullableText($profile['address'] ?? null),
            'business_website_url' => $this->normalizeNullableText($profile['website_url'] ?? null),
        ];
    }

    private function normalizeDiscountScope(mixed $value): string
    {
        $discountScope = trim((string) $value);
        return $discountScope !== '' ? $discountScope : 'quotation_total';
    }

    private function normalizeMoneyValue(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(',', '', $normalized);
        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? '';

        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function normalizeDecimalValue(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeDateString($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * @param array<int, array<string, mixed>> $requestProjects
     * @return array<int, array<string, mixed>>
     */
    private function extractProjectItemsFromRequest(array $requestProjects): array
    {
        $items = [];

        foreach ($requestProjects as $requestProject) {
            if (!is_array($requestProject)) {
                continue;
            }

            $serviceIds = json_decode((string) ($requestProject['service_ids_json'] ?? '[]'), true);

            $items[] = [
                '_request_project_index' => (int) ($requestProject['request_project_index'] ?? 0),
                'project_title' => trim((string) ($requestProject['project_title'] ?? '')),
                'project_description' => trim((string) ($requestProject['project_description'] ?? '')),
                'estimated_amount' => $this->normalizeMoneyValue($requestProject['estimated_amount'] ?? null),
                'category_id' => (int) ($requestProject['category_id'] ?? 0),
                'service_ids' => $this->normalizeServiceIds(is_array($serviceIds) ? $serviceIds : []),
                'payment_type' => $this->normalizePaymentType($requestProject['payment_type'] ?? 'fixed_rate'),
                'hourly_hours' => $this->normalizeDecimalValue($requestProject['hourly_hours'] ?? null),
                'scope' => trim((string) ($requestProject['scope'] ?? '')),
                'estimate_type' => trim((string) ($requestProject['estimate_type'] ?? '')),
                'zip_code' => trim((string) ($requestProject['zip_code'] ?? '')),
                'deadline' => trim((string) ($requestProject['deadline'] ?? '')),
                'delivery_date' => $this->normalizeDateString($requestProject['delivery_date'] ?? null),
                'deadline_date' => $this->normalizeDateString($requestProject['deadline_date'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<int, int>
     */
    private function extractTemplateClauseIds(array $contract): array
    {
        $clauses = is_array($contract['clauses'] ?? null) ? $contract['clauses'] : [];
        $ids = array_map(static fn(array $clause): int => (int) ($clause['id'] ?? 0), $clauses);

        return array_values(array_filter($ids, static fn(int $clauseId): bool => $clauseId > 0));
    }

    private function normalizeDateTimeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if ($text === '') {
            return null;
        }

        $timestamp = strtotime($text);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }



    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validateQuotationPayload(array $data): array
    {
        $errors = [];

        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : 0;
        if ($customerId < 1) {
            $errors['customer_id'] = 'Customer id is required.';
        }

        return $errors;
    }
}
