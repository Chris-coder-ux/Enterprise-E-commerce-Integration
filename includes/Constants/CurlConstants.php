<?php
/**
 * Definiciones de constantes cURL faltantes
 * 
 * Este archivo define las constantes cURL que no están disponibles
 * en todas las versiones de cURL o PHP.
 */

// Solo definir las constantes si no están ya definidas
if (!defined('CURLE_HTTP2_ERROR')) {
    define('CURLE_HTTP2_ERROR', 16);
}

if (!defined('CURLE_URL_MALFORMED')) {
    define('CURLE_URL_MALFORMED', 3);
}

if (!defined('CURLE_NOT_BUILT_IN')) {
    define('CURLE_NOT_BUILT_IN', 4);
}

if (!defined('CURLE_REMOTE_ACCESS_DENIED')) {
    define('CURLE_REMOTE_ACCESS_DENIED', 9);
}

if (!defined('CURLE_INTERFACE_FAILED')) {
    define('CURLE_INTERFACE_FAILED', 45);
}

if (!defined('CURLE_UNKNOWN_OPTION')) {
    define('CURLE_UNKNOWN_OPTION', 48);
}

if (!defined('CURLE_PEER_FAILED_VERIFICATION')) {
    define('CURLE_PEER_FAILED_VERIFICATION', 51);
}

if (!defined('CURLE_USE_SSL_FAILED')) {
    define('CURLE_USE_SSL_FAILED', 64);
}

if (!defined('CURLE_SEND_FAIL_REWIND')) {
    define('CURLE_SEND_FAIL_REWIND', 65);
}

if (!defined('CURLE_SSL_ENGINE_INITFAILED')) {
    define('CURLE_SSL_ENGINE_INITFAILED', 66);
}

if (!defined('CURLE_LOGIN_DENIED')) {
    define('CURLE_LOGIN_DENIED', 67);
}

if (!defined('CURLE_TFTP_NOTFOUND')) {
    define('CURLE_TFTP_NOTFOUND', 68);
}

if (!defined('CURLE_TFTP_PERM')) {
    define('CURLE_TFTP_PERM', 69);
}

if (!defined('CURLE_REMOTE_DISK_FULL')) {
    define('CURLE_REMOTE_DISK_FULL', 70);
}

if (!defined('CURLE_TFTP_ILLEGAL')) {
    define('CURLE_TFTP_ILLEGAL', 71);
}

if (!defined('CURLE_TFTP_UNKNOWNID')) {
    define('CURLE_TFTP_UNKNOWNID', 72);
}

if (!defined('CURLE_REMOTE_FILE_EXISTS')) {
    define('CURLE_REMOTE_FILE_EXISTS', 73);
}

if (!defined('CURLE_TFTP_NOSUCHUSER')) {
    define('CURLE_TFTP_NOSUCHUSER', 74);
}

if (!defined('CURLE_CONV_FAILED')) {
    define('CURLE_CONV_FAILED', 75);
}

if (!defined('CURLE_CONV_REQD')) {
    define('CURLE_CONV_REQD', 76);
}

if (!defined('CURLE_REMOTE_FILE_NOT_FOUND')) {
    define('CURLE_REMOTE_FILE_NOT_FOUND', 78);
}

if (!defined('CURLE_SSL_SHUTDOWN_FAILED')) {
    define('CURLE_SSL_SHUTDOWN_FAILED', 80);
}

if (!defined('CURLE_AGAIN')) {
    define('CURLE_AGAIN', 81);
}

if (!defined('CURLE_SSL_CRL_BADFILE')) {
    define('CURLE_SSL_CRL_BADFILE', 82);
}

if (!defined('CURLE_SSL_ISSUER_ERROR')) {
    define('CURLE_SSL_ISSUER_ERROR', 83);
}

if (!defined('CURLE_FTP_PRET_FAILED')) {
    define('CURLE_FTP_PRET_FAILED', 84);
}

if (!defined('CURLE_RTSP_CSEQ_ERROR')) {
    define('CURLE_RTSP_CSEQ_ERROR', 85);
}

if (!defined('CURLE_RTSP_SESSION_ERROR')) {
    define('CURLE_RTSP_SESSION_ERROR', 86);
}

if (!defined('CURLE_FTP_BAD_FILE_LIST')) {
    define('CURLE_FTP_BAD_FILE_LIST', 87);
}

if (!defined('CURLE_CHUNK_FAILED')) {
    define('CURLE_CHUNK_FAILED', 88);
}

if (!defined('CURLE_NO_CONNECTION_AVAILABLE')) {
    define('CURLE_NO_CONNECTION_AVAILABLE', 89);
}

if (!defined('CURLE_SSL_INVALIDCERTSTATUS')) {
    define('CURLE_SSL_INVALIDCERTSTATUS', 91);
}

if (!defined('CURLE_HTTP2_STREAM')) {
    define('CURLE_HTTP2_STREAM', 92);
}

if (!defined('CURLE_RECURSIVE_API_CALL')) {
    define('CURLE_RECURSIVE_API_CALL', 93);
}

if (!defined('CURLE_AUTH_ERROR')) {
    define('CURLE_AUTH_ERROR', 94);
}

if (!defined('CURLE_FTP_ACCOUNT')) {
    define('CURLE_FTP_ACCOUNT', 95);
}

if (!defined('CURLE_FTP_ACCEPT_FAILED')) {
    define('CURLE_FTP_ACCEPT_FAILED', 96);
}

if (!defined('CURLE_FTP_ACCEPT_TIMEOUT')) {
    define('CURLE_FTP_ACCEPT_TIMEOUT', 97);
}

if (!defined('CURLE_HTTP2')) {
    define('CURLE_HTTP2', 16);
}

if (!defined('CURLE_FTP_COULDNT_SET_TYPE')) {
    define('CURLE_FTP_COULDNT_SET_TYPE', 17);
}

if (!defined('CURLE_QUOTE_ERROR')) {
    define('CURLE_QUOTE_ERROR', 21);
}

if (!defined('CURLE_UPLOAD_FAILED')) {
    define('CURLE_UPLOAD_FAILED', 25);
}

if (!defined('CURLE_RANGE_ERROR')) {
    define('CURLE_RANGE_ERROR', 33);
}
