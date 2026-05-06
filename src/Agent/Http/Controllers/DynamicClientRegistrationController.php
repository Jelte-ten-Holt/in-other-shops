<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InOtherShops\Agent\Events\DynamicClientRegistered;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * RFC 7591 OAuth 2.0 Dynamic Client Registration.
 *
 * Accepts a registration request, validates the metadata, asks Passport to
 * persist a new authorization-code grant client, and returns the issued
 * credentials per §3.2.1. Every registration is logged on the `agent`
 * channel (see AgentLogSubscriber).
 *
 * Co-work posts here when its custom-connector form is left with blank
 * OAuth advanced-settings fields.
 */
final class DynamicClientRegistrationController
{
    private const array GRANTED_GRANT_TYPES = ['authorization_code', 'refresh_token'];

    private const array GRANTED_RESPONSE_TYPES = ['code'];

    private const array ALLOWED_AUTH_METHODS = ['client_secret_basic', 'client_secret_post', 'none'];

    public function __construct(
        private readonly ClientRepository $clients,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->hasValidInitialAccessToken($request)) {
            return response()->json(
                $this->error('invalid_token', 'Initial access token is required.'),
                401,
                ['WWW-Authenticate' => 'Bearer'],
            );
        }

        if ($this->clientCapReached()) {
            return response()->json(
                $this->error('too_many_clients', 'The maximum number of dynamically-registered clients has been reached.'),
                429,
            );
        }

        $validated = $this->validateRegistration($request);

        if (isset($validated['error'])) {
            return response()->json($validated, 400);
        }

        $client = $this->clients->createAuthorizationCodeGrantClient(
            name: $validated['client_name'],
            redirectUris: $validated['redirect_uris'],
            confidential: $validated['confidential'],
        );

        DynamicClientRegistered::dispatch(
            (string) $client->getKey(),
            (string) $client->name,
            $validated['redirect_uris'],
            $validated['confidential'],
        );

        return response()->json(
            $this->registrationResponse($client, $validated),
            201,
        );
    }

    /**
     * When `agent.auth.oauth.dcr.initial_access_token` is set, RFC 7591 §3
     * authenticated registration is enforced. Empty config = open
     * registration (backwards-compatible default). A non-empty expected
     * value is compared in constant time.
     */
    private function hasValidInitialAccessToken(Request $request): bool
    {
        $expected = (string) config('agent.auth.oauth.dcr.initial_access_token', '');

        if ($expected === '') {
            return true;
        }

        $presented = (string) $request->bearerToken();

        return $presented !== '' && hash_equals($expected, $presented);
    }

    private function clientCapReached(): bool
    {
        $cap = (int) config('agent.auth.oauth.dcr.max_clients', 0);

        if ($cap <= 0) {
            return false;
        }

        return Client::query()->count() >= $cap;
    }

    /**
     * @return array{error: string, error_description: string}|array{client_name: string, redirect_uris: array<int, string>, scope: string, token_endpoint_auth_method: string, confidential: bool}
     */
    private function validateRegistration(Request $request): array
    {
        $redirectUris = $request->input('redirect_uris', []);

        if (! is_array($redirectUris) || $redirectUris === []) {
            return $this->error('invalid_redirect_uri', 'redirect_uris is required and must be a non-empty array.');
        }

        foreach ($redirectUris as $uri) {
            if (! is_string($uri) || filter_var($uri, FILTER_VALIDATE_URL) === false) {
                return $this->error('invalid_redirect_uri', 'Each redirect_uri must be a valid absolute URL.');
            }
        }

        foreach ((array) $request->input('grant_types', ['authorization_code']) as $grant) {
            if (! in_array($grant, self::GRANTED_GRANT_TYPES, true)) {
                return $this->error('invalid_client_metadata', "Unsupported grant_type: {$grant}.");
            }
        }

        foreach ((array) $request->input('response_types', ['code']) as $response) {
            if (! in_array($response, self::GRANTED_RESPONSE_TYPES, true)) {
                return $this->error('invalid_client_metadata', "Unsupported response_type: {$response}.");
            }
        }

        $authMethod = (string) $request->input('token_endpoint_auth_method', 'client_secret_basic');
        if (! in_array($authMethod, self::ALLOWED_AUTH_METHODS, true)) {
            return $this->error('invalid_client_metadata', "Unsupported token_endpoint_auth_method: {$authMethod}.");
        }

        $supportedScope = (string) config('agent.auth.oauth.scope', 'agent');
        $requestedScope = (string) $request->input('scope', $supportedScope);
        foreach (explode(' ', trim($requestedScope)) as $token) {
            if ($token !== '' && $token !== $supportedScope) {
                return $this->error('invalid_client_metadata', "Unsupported scope: {$token}.");
            }
        }

        return [
            'client_name' => (string) $request->input('client_name', 'Dynamically Registered Client'),
            'redirect_uris' => array_values(array_map('strval', $redirectUris)),
            'scope' => $requestedScope,
            'token_endpoint_auth_method' => $authMethod,
            'confidential' => $authMethod !== 'none',
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function registrationResponse(Client $client, array $metadata): array
    {
        $response = [
            'client_id' => (string) $client->getKey(),
            'client_id_issued_at' => $client->created_at?->timestamp ?? time(),
            'client_secret_expires_at' => 0,
            'client_name' => $metadata['client_name'],
            'redirect_uris' => $metadata['redirect_uris'],
            'grant_types' => self::GRANTED_GRANT_TYPES,
            'response_types' => self::GRANTED_RESPONSE_TYPES,
            'scope' => $metadata['scope'],
            'token_endpoint_auth_method' => $metadata['token_endpoint_auth_method'],
        ];

        if ($metadata['confidential']) {
            $response['client_secret'] = (string) ($client->plainSecret ?? $client->secret);
        }

        return $response;
    }

    /**
     * @return array{error: string, error_description: string}
     */
    private function error(string $code, string $description): array
    {
        return ['error' => $code, 'error_description' => $description];
    }
}
