package org.clinica.service;

import org.clinica.repository.GoogleAccountGateway.GoogleIdentity;

/** Puerto OIDC. La implementacion real valida criptograficamente el ID token de Google. */
public interface GoogleIdentityProvider {

    String authorizationUrl(AuthorizationRequest request);

    GoogleIdentity exchange(ExchangeRequest request);

    record AuthorizationRequest(
            String state,
            String nonce,
            String codeChallenge,
            String redirectUri) {
    }

    record ExchangeRequest(
            String code,
            String codeVerifier,
            String expectedNonce,
            String redirectUri) {
    }
}
