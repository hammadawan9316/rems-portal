<?php

namespace App\Controllers\Api;

use App\Models\CustomerModel;

class CustomerController extends BaseApiController
{
    public function create()
    {
        $data = $this->getRequestData(false);
        $errors = $this->validateCustomerPayload($data);
        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        $customerModel = new CustomerModel();
        $email = trim((string) $data['email']);

        $existing = $customerModel->findByEmail($email);
        if (is_array($existing)) {
            return $this->res->badRequest('Customer with this email already exists.', ['email' => 'Email must be unique.']);
        }

        $payload = [
            'name' => trim((string) $data['name']),
            'email' => $email,
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'company' => trim((string) ($data['company'] ?? '')) ?: null,
            'user_id' => null,
        ];

        $customerModel->insert($payload);
        $customerId = (int) $customerModel->getInsertID();

        if ($customerId < 1) {
            return $this->res->serverError('Customer could not be created.');
        }

        return $this->res->created($customerModel->find($customerId), 'Customer created successfully');
    }

    public function index()
    {
        $customerModel = new CustomerModel();
        $customers = $customerModel->orderBy('id', 'DESC')->findAll();

        return $this->res->ok($customers, 'Customers retrieved successfully');
    }

    public function show(int $id)
    {
        $customerModel = new CustomerModel();
        $customer = $customerModel->find($id);

        if (!is_array($customer)) {
            return $this->res->notFound('Customer not found');
        }

        return $this->res->ok($customer, 'Customer retrieved successfully');
    }

    public function update(int $id)
    {
        $customerModel = new CustomerModel();
        $customer = $customerModel->find($id);

        if (!is_array($customer)) {
            return $this->res->notFound('Customer not found');
        }

        $data = $this->getRequestData(false);
        $payload = [];

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 160) {
                return $this->res->validation(['name' => 'Customer name must be between 2 and 160 characters.']);
            }
            $payload['name'] = $name;
        }

        if (isset($data['email'])) {
            $email = trim((string) $data['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                return $this->res->validation(['email' => 'A valid email is required and must not exceed 190 characters.']);
            }
            $existing = $customerModel->where('email', $email)->where('id !=', $id)->first();
            if (is_array($existing)) {
                return $this->res->badRequest('Email already in use.', ['email' => 'Email must be unique.']);
            }
            $payload['email'] = $email;
        }

        if (isset($data['phone'])) {
            $phone = trim((string) $data['phone']);
            if ($phone !== '' && !preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
                return $this->res->validation(['phone' => 'Phone must be in valid E.164 format (e.g. +14155552671).']);
            }
            $payload['phone'] = $phone !== '' ? $phone : null;
        }

        if (isset($data['company'])) {
            $payload['company'] = trim((string) $data['company']) ?: null;
        }

        if ($payload === []) {
            return $this->res->badRequest('No customer fields supplied to update.');
        }

        $customerModel->update($id, $payload);

        return $this->res->ok($customerModel->find($id), 'Customer updated successfully');
    }

    public function delete(int $id)
    {
        $customerModel = new CustomerModel();
        $customer = $customerModel->find($id);

        if (!is_array($customer)) {
            return $this->res->notFound('Customer not found');
        }

        $customerModel->delete($id);

        return $this->res->ok(null, 'Customer deleted successfully');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validateCustomerPayload(array $data): array
    {
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $errors['name'] = 'Customer name is required and must be between 2 and 160 characters.';
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors['email'] = 'A valid email is required and must not exceed 190 characters.';
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
            $errors['phone'] = 'Phone must be in valid E.164 format (e.g. +14155552671).';
        }

        return $errors;
    }
}
