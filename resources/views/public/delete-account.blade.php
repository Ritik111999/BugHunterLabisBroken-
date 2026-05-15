<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} | {{ config('app.name', 'WeChirp') }}</title>
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        /* Half width of viewport (not dependent on Tailwind fraction utilities) */
        .delete-account-shell {
            box-sizing: border-box;
            width: 100%;
            max-width: 100%;
            margin-left: auto;
            margin-right: auto;
            padding-left: 1rem;
            padding-right: 1rem;
        }

        @media (min-width: 640px) {
            .delete-account-shell {
                width: 50vw;
                max-width: 50vw;
            }
        }
    </style>
</head>

<body class="min-h-screen bg-white text-slate-900 antialiased">
    <div class="delete-account-shell min-w-0 py-10 sm:py-14">
        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 class="mb-10 text-center text-2xl font-bold tracking-tight text-black sm:text-3xl">{{ $title }}</h1>

        <form id="delete-account-form" method="post" action="{{ route('delete-account.submit') }}" class="space-y-6">
                    @csrf

                    <div>
                        <label for="name" class="mb-2 block text-sm font-semibold text-black">Your Full Name</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="255" placeholder="Enter your full name"
                            class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-emerald-700 focus:outline-none focus:ring-1 focus:ring-emerald-700" />
                        @error('name')
                            <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="email" class="mb-2 block text-sm font-semibold text-black">Your Email Address</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" placeholder="Enter your email address" autocomplete="email"
                            class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-emerald-700 focus:outline-none focus:ring-1 focus:ring-emerald-700" />
                        @error('email')
                            <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <p id="otp-status" class="mt-2 hidden text-sm"></p>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button type="button" id="btn-send-otp"
                            class="rounded-md bg-emerald-800 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-900 focus:outline-none focus:ring-2 focus:ring-emerald-800 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60">
                            Send OTP
                        </button>
                    </div>

                    <div id="otp-verify-row" class="rounded-md border border-slate-200 bg-slate-50/80 p-4">
                        <label for="otp" class="mb-2 block text-sm font-semibold text-black">Verification code</label>
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <input id="otp" type="text" inputmode="numeric" maxlength="6" placeholder="6-digit code" autocomplete="one-time-code"
                                class="w-full max-w-xs rounded-md border border-slate-200 bg-white px-3 py-2.5 text-sm tracking-widest text-slate-900 placeholder:text-slate-400 focus:border-emerald-700 focus:outline-none focus:ring-1 focus:ring-emerald-700 sm:flex-1" />
                            <button type="button" id="btn-verify-otp"
                                class="shrink-0 rounded-md border border-emerald-800 bg-white px-4 py-2.5 text-sm font-semibold text-emerald-900 transition hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-800 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60">
                                Verify code
                            </button>
                        </div>
                        <p id="verify-hint" class="mt-2 text-xs text-slate-500">Enter the code from your email, then click Verify code.</p>
                    </div>

                    <div>
                        <label for="phone" class="mb-2 block text-sm font-semibold text-black">Phone number <span class="font-normal text-slate-500">(optional)</span></label>
                        <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" maxlength="32" placeholder="Enter your phone number"
                            class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-emerald-700 focus:outline-none focus:ring-1 focus:ring-emerald-700" />
                        @error('phone')
                            <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="reason" class="mb-2 block text-sm font-semibold text-black">Reason for Deletion</label>
                        <textarea id="reason" name="reason" rows="6" maxlength="2000" placeholder="Please explain your reason for account deletion"
                            class="w-full resize-y rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-emerald-700 focus:outline-none focus:ring-1 focus:ring-emerald-700">{{ old('reason') }}</textarea>
                        @error('reason')
                            <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-start gap-3 border-t border-slate-100 pt-4">
                        <input id="confirm_deletion" name="confirm_deletion" type="checkbox" value="1"
                            class="mt-1 h-4 w-4 rounded border-slate-300 text-emerald-800 focus:ring-emerald-700"
                            {{ old('confirm_deletion') ? 'checked' : '' }} />
                        <label for="confirm_deletion" class="text-sm text-slate-700">
                            I confirm I want to delete my account <span class="text-red-600">*</span>
                        </label>
                    </div>
                    @error('confirm_deletion')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    <button type="submit" id="btn-submit" disabled
                        class="w-full rounded-md bg-emerald-800 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-900 focus:outline-none focus:ring-2 focus:ring-emerald-800 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500">
                        Request Account Deletion
                    </button>
                    <p id="submit-hint" class="text-center text-xs text-slate-500">Verify your email with the code before you can submit.</p>
                </form>

        @php
            $verifiedEmail = session('delete_account_email_verified');
            $verifiedExp = (int) session('delete_account_email_verified_expires', 0);
            $otpVerifiedForForm =
                is_string($verifiedEmail) &&
                $verifiedExp > now()->getTimestamp() &&
                strtolower((string) old('email', $verifiedEmail)) === strtolower($verifiedEmail);
        @endphp

        <footer class="mt-16 border-t border-slate-200 pt-8 text-center text-xs text-slate-500">
            <p class="text-slate-600">&copy; {{ date('Y') }} {{ config('app.name', 'WeChirp') }}. All rights reserved.</p>
            <p class="mt-3">
                <a href="{{ $privacyUrl }}" class="text-emerald-900 hover:underline">Privacy Policy</a>
                <span class="mx-2 text-slate-300">|</span>
                <a href="{{ $termsUrl }}" class="text-emerald-900 hover:underline">Terms &amp; Conditions</a>
                <span class="mx-2 text-slate-300">|</span>
                <a href="{{ $aboutUrl }}" class="text-emerald-900 hover:underline">About Us</a>
            </p>
            <p class="mt-4">
                Support:
                <a href="mailto:{{ $supportEmail }}" class="font-medium text-emerald-900 hover:underline">{{ $supportEmail }}</a>
            </p>
        </footer>
        </div>
    </div>

    <script>
        (function () {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const sendUrl = @json($sendOtpUrl);
            const verifyUrl = @json($verifyOtpUrl);
            const emailInput = document.getElementById('email');
            const otpInput = document.getElementById('otp');
            const btnSend = document.getElementById('btn-send-otp');
            const btnVerify = document.getElementById('btn-verify-otp');
            const btnSubmit = document.getElementById('btn-submit');
            const otpStatus = document.getElementById('otp-status');
            const submitHint = document.getElementById('submit-hint');

            let emailVerified = @json($otpVerifiedForForm);

            function setOtpStatus(msg, ok) {
                if (!otpStatus) return;
                otpStatus.textContent = msg;
                otpStatus.classList.remove('hidden', 'text-red-600', 'text-emerald-800');
                otpStatus.classList.add(ok ? 'text-emerald-800' : 'text-red-600');
            }

            function setVerifiedState(on) {
                emailVerified = on;
                if (btnSubmit) btnSubmit.disabled = !on;
                if (submitHint) submitHint.classList.toggle('hidden', on);
            }

            async function postJson(url, body) {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(body),
                    credentials: 'same-origin',
                });
                const data = await res.json().catch(function () {
                    return {};
                });
                return { ok: res.ok, data: data };
            }

            btnSend?.addEventListener('click', async function () {
                const email = (emailInput?.value || '').trim();
                if (!email) {
                    setOtpStatus('Please enter your email address first.', false);
                    return;
                }
                setVerifiedState(false);
                btnSend.disabled = true;
                setOtpStatus('Sending…', true);
                const { ok, data } = await postJson(sendUrl, { email: email });
                btnSend.disabled = false;
                if (ok && data.success) {
                    setOtpStatus(data.message || 'Code sent. Check your inbox.', true);
                } else {
                    setOtpStatus(data.message || 'Could not send code.', false);
                }
            });

            btnVerify?.addEventListener('click', async function () {
                const email = (emailInput?.value || '').trim();
                const otp = (otpInput?.value || '').trim();
                if (!email || otp.length !== 6) {
                    setOtpStatus('Enter your email and the 6-digit code.', false);
                    return;
                }
                btnVerify.disabled = true;
                const { ok, data } = await postJson(verifyUrl, { email: email, otp: otp });
                btnVerify.disabled = false;
                if (ok && data.success) {
                    setOtpStatus(data.message || 'Email verified.', true);
                    setVerifiedState(true);
                } else {
                    setVerifiedState(false);
                    setOtpStatus(data.message || 'Verification failed.', false);
                }
            });

            emailInput?.addEventListener('input', function () {
                setVerifiedState(false);
                otpStatus?.classList.add('hidden');
            });

            if (emailVerified) {
                setOtpStatus('Email verified. You can complete and submit the form.', true);
            }
            setVerifiedState(emailVerified);
        })();
    </script>
    @include('partials.pwa-register')
</body>

</html>
