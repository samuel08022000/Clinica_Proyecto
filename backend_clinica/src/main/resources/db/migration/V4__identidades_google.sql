-- Identidades externas para inicio de sesion con Google.
-- No se almacenan access tokens, refresh tokens ni secretos del proveedor.

ALTER TABLE public.usuarios
    ALTER COLUMN password_hash DROP NOT NULL;

ALTER TABLE public.usuarios
    ADD CONSTRAINT ck_usuarios_password_hash_seguro
    CHECK (password_hash IS NULL OR length(trim(password_hash)) >= 20);

CREATE TABLE public.usuarios_identidades_externas (
    secuencial             bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    cod_identidad          text GENERATED ALWAYS AS ('IDE' || lpad(secuencial::text, 8, '0')) STORED PRIMARY KEY,
    cod_usu                text NOT NULL REFERENCES public.usuarios(cod_usu) ON DELETE RESTRICT,
    proveedor              varchar(20) NOT NULL,
    identificador_externo  varchar(255) NOT NULL,
    email_proveedor        varchar(254),
    email_verificado       boolean NOT NULL DEFAULT false,
    vinculado_en           timestamptz NOT NULL DEFAULT now(),
    actualizado_en         timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ck_identidad_proveedor CHECK (proveedor IN ('google')),
    CONSTRAINT uq_identidad_externa UNIQUE (proveedor, identificador_externo),
    CONSTRAINT uq_usuario_proveedor UNIQUE (cod_usu, proveedor)
);

CREATE INDEX ix_identidad_usuario
    ON public.usuarios_identidades_externas(cod_usu);

REVOKE ALL ON public.usuarios_identidades_externas FROM PUBLIC;
GRANT SELECT, INSERT, UPDATE ON public.usuarios_identidades_externas TO CURRENT_USER;
GRANT USAGE, SELECT ON SEQUENCE public.usuarios_identidades_externas_secuencial_seq TO CURRENT_USER;
