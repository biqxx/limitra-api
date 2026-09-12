<?php

namespace App\Enums;

enum ApiErrorCode: string
{
    case BadRequest = 'BAD_REQUEST';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case Conflict = 'CONFLICT';
    case ValidationFailed = 'VALIDATION_FAILED';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case RequestFailed = 'REQUEST_FAILED';
    case ServerError = 'SERVER_ERROR';
    case GatewayError = 'GATEWAY_ERROR';
    case OutOfStock = 'OUT_OF_STOCK';
    case InvalidPromotion = 'INVALID_PROMOTION';
    case QuoteExpired = 'QUOTE_EXPIRED';
    case InvalidOrderTransition = 'INVALID_ORDER_TRANSITION';
    case PaymentPending = 'PAYMENT_PENDING';
    case InsufficientBalance = 'INSUFFICIENT_BALANCE';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';

    public static function forStatus(int $status): self
    {
        return match ($status) {
            400 => self::BadRequest,
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::ResourceNotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            422 => self::ValidationFailed,
            429 => self::TooManyRequests,
            502, 503, 504 => self::GatewayError,
            default => $status >= 500 ? self::ServerError : self::RequestFailed,
        };
    }
}
