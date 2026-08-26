<?php

declare(strict_types=1);

namespace Ziming\LaravelMyinfoSg\Services\MyinfoV6;

use Illuminate\Http\Request;
use Ziming\LaravelMyinfoSg\Data\MyinfoV6\ValidatedAuthorizationCallback;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\AuthorizationResponseException;
use Ziming\LaravelMyinfoSg\Exceptions\MyinfoV6\InvalidAuthorizationCallbackException;

final readonly class AuthorizationCallbackValidator
{
    public function __construct(private AuthorizationTransactionStore $transactions)
    {
    }

    public function validate(Request $request): ValidatedAuthorizationCallback
    {
        $state = $request->query('state');

        if (! is_string($state) || preg_match('~\A[A-Za-z0-9/+_\-=.]{1,255}\z~D', $state) !== 1) {
            throw new InvalidAuthorizationCallbackException('Authorization callback state is invalid.');
        }

        $transaction = $this->transactions->pull($state);

        if ($transaction === null) {
            throw new InvalidAuthorizationCallbackException('No matching authorization transaction was found.');
        }

        $issuer = $request->query('iss');

        if (! is_string($issuer) || ! hash_equals($transaction->issuer, $issuer)) {
            throw new InvalidAuthorizationCallbackException('Authorization callback issuer is invalid.');
        }

        $code = $request->query('code');

        if ($request->query->has('error')) {
            if (is_string($code) && trim($code) !== '') {
                throw new InvalidAuthorizationCallbackException('Authorization callback parameters are invalid.');
            }

            throw new AuthorizationResponseException($this->safeErrorCode($request->query('error')));
        }

        if (! is_string($code) || trim($code) === '') {
            throw new InvalidAuthorizationCallbackException('Authorization callback code is missing.');
        }

        return new ValidatedAuthorizationCallback($code, $transaction);
    }

    private function safeErrorCode(mixed $error): string
    {
        if (! is_string($error) || preg_match('/\A[A-Za-z0-9._-]{1,128}\z/D', $error) !== 1) {
            return 'authorization_error';
        }

        return $error;
    }
}
