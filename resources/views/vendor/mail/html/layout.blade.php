<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}

/* "The Desk" dark mode. Inlined light values are non-important, so these
   !important overrides win where the client honours prefers-color-scheme
   (Apple Mail, iOS Mail, Outlook.com). Clients that ignore it keep light. */
@media (prefers-color-scheme: dark) {
body, .wrapper, .body {
background-color: #12100c !important;
color: #a49a86 !important;
}

.inner-body {
background-color: #1e1b15 !important;
border-color: #2e2a21 !important;
box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4) !important;
}

.content-cell h1, .content-cell h2, .content-cell h3, .header a, .brand-name {
color: #f3efe4 !important;
}

p, .table td {
color: #a49a86 !important;
}

a {
color: #f3efe4 !important;
}

.brand-mark {
color: #c9a35c !important;
}

.button-blue, .button-primary, .button-green, .button-success {
background-color: #f3efe4 !important;
border-color: #f3efe4 !important;
color: #1d1a15 !important;
}

.panel-content {
background-color: #26221a !important;
border-color: #3a3428 !important;
color: #d8b877 !important;
}

.panel-content p {
color: #d8b877 !important;
}

.subcopy {
border-top-color: #2e2a21 !important;
}

.subcopy p, .footer p, .footer a {
color: #8b8370 !important;
}

.subcopy a {
color: #d8b877 !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
