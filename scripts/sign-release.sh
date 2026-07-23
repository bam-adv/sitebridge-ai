#!/usr/bin/env bash
#
# Sign a SiteBridge AI release .zip with the Ed25519 release-signing key so the
# plugin's self-updater will accept it. Produces "<zip>.sig" (base64 detached
# signature). Attach BOTH the .zip and the .sig to the GitHub release.
#
# The PRIVATE key is read from $SB_SIGN_KEY (base64) and is NEVER stored in this
# repo. Keep it in a password manager; export it only for the signing command.
#
# Usage:
#   SB_SIGN_KEY='<base64 Ed25519 secret key>' scripts/sign-release.sh path/to/sitebridge-ai.zip
#
set -euo pipefail

ZIP="${1:-}"
if [[ -z "$ZIP" ]]; then
	echo "usage: SB_SIGN_KEY=<base64 secret> $0 <path-to.zip>" >&2
	exit 2
fi
if [[ ! -f "$ZIP" ]]; then
	echo "error: no such file: $ZIP" >&2
	exit 2
fi
if [[ -z "${SB_SIGN_KEY:-}" ]]; then
	echo "error: set SB_SIGN_KEY to the base64 Ed25519 secret key (from your password manager)" >&2
	exit 2
fi

php -r '
	$zip = $argv[1];
	$sk  = base64_decode( getenv( "SB_SIGN_KEY" ), true );
	if ( $sk === false || strlen( $sk ) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) {
		fwrite( STDERR, "error: SB_SIGN_KEY is not a valid base64 Ed25519 secret key\n" );
		exit( 1 );
	}
	$bytes = file_get_contents( $zip );
	if ( $bytes === false ) { fwrite( STDERR, "error: cannot read $zip\n" ); exit( 1 ); }
	$sig = sodium_crypto_sign_detached( $bytes, $sk );
	file_put_contents( $zip . ".sig", base64_encode( $sig ) . "\n" );
	fwrite( STDERR, "signed: " . $zip . ".sig\n" );
' "$ZIP"
