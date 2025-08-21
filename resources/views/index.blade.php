@extends('layouts.app')
@section('content')
    @php
        $host = request()->getHost();
    @endphp

    {{-- Tailwind-first minimal layouts for Komiut and 2Safiri. Ensure Tailwind is loaded in your layout. --}}

    @if(Str::contains($host, 'komiut.com'))
        {{-- ================= KOMIUT: Modern Corporate Minimal ================= --}}
        <div class='container-fluid bg' id='home'>

        <div class="min-h-screen bg-[#F7F8FA] text-[#1C1C1C]">
            <!-- Nav -->
            <header class="sticky top-0 z-30 bg-white/80 backdrop-blur border-b border-gray-100">
                <div class="container mx-auto max-w-7xl px-4 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <img src="{{ asset('images/komiut-logo.svg') }}" alt="Komiut" class="h-7 w-auto"/>
                        <span class="sr-only">Komiut</span>
                    </div>
                    <nav class="hidden md:flex items-center gap-8 text-sm">
                        <a href="#features" class="hover:text-[#2E86DE]">Features</a>
                        <a href="#how" class="hover:text-[#2E86DE]">How it works</a>
                        <a href="#security" class="hover:text-[#2E86DE]">Security</a>
                    </nav>
                    <div class="hidden md:flex items-center gap-3">
                        <a href="{{ route('login') }}" class="text-sm hover:text-[#2E86DE]">Sign in</a>
                        <a href="{{ url('/request-access') }}" class="text-sm px-4 py-2 rounded-xl bg-[#0B1D3A] text-white hover:opacity-90">Request access</a>
                    </div>
                </div>
            </header>

            <!-- Hero -->
            <section class="relative overflow-hidden">
                <div class="absolute inset-0 bg-gradient-to-br from-white via-white to-[#ECF1F8]"></div>
                <div class="relative container mx-auto max-w-7xl px-4 py-20 md:py-28 grid md:grid-cols-2 gap-12 items-center">
                    <div>
                        <h1 class="text-4xl md:text-5xl font-semibold leading-tight tracking-tight">
                            Your Complete <span class="text-[#0B1D3A]">Fleet Money</span> Management Hub
                        </h1>
                        <p class="mt-5 text-gray-600 max-w-xl">Control policies, track spend, and optimize fueling across organizations with precision and clarity.</p>
                        <div class="mt-8 flex flex-wrap gap-3">
                            <a href="#features" class="px-5 py-3 rounded-xl bg-[#2E86DE] text-white hover:opacity-90">Explore features</a>
                            <a href="{{ route('login') }}" class="px-5 py-3 rounded-xl border border-gray-300 hover:border-gray-400">Sign in</a>
                        </div>
                        <div class="mt-8 flex items-center gap-6 text-xs text-gray-500">
                            <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-[#2E86DE]"></span> Real-time controls</div>
                            <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-[#0B1D3A]"></span> RBAC & audit-ready</div>
                        </div>
                    </div>
                    <div class="md:pl-8">
                        <div class="aspect-[4/3] rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                            <img src="{{ asset('images/hero-dashboard-light.png') }}" alt="Komiut dashboard" class="w-full h-full object-cover"/>
                        </div>
                        <p class="sr-only">Dashboard preview</p>
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section id="features" class="py-14 md:py-20">
                <div class="container mx-auto max-w-7xl px-4">
                    <div class="max-w-2xl">
                        <h2 class="text-2xl md:text-3xl font-semibold">Core features</h2>
                        <p class="mt-2 text-gray-600">Built for finance teams, fleet ops, and compliance.</p>
                    </div>
                    <div class="mt-10 grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                        @php $items = [
            ['title' => 'Real-time Spend Tracking','desc' => 'Live visibility on fueling and expenses per vehicle, route, or org.'],
            ['title' => 'Policy & Limit Engine','desc' => 'Set per-vehicle limits, categories, and approval thresholds.'],
            ['title' => 'Approvals & Workflows','desc' => 'Streamlined requests with role-based routing and audit logs.'],
            ['title' => 'Merchant Controls','desc' => 'Whitelist stations and enforce pricing and product rules.'],
            ['title' => 'Reports & Analytics','desc' => 'Instant reports, exports, and scheduled insights.'],
            ['title' => 'Secure RBAC & SSO','desc' => 'Granular roles, SSO support, and comprehensive auditing.'],
          ]; @endphp
                        @foreach($items as $i)
                            <div class="p-6 rounded-2xl border border-gray-200 bg-white hover:shadow-sm transition">
                                <h3 class="font-medium">{{ $i['title'] }}</h3>
                                <p class="mt-2 text-sm text-gray-600">{{ $i['desc'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <!-- How it works -->
            <section id="how" class="py-14 md:py-20 border-t border-gray-100 bg-white">
                <div class="container mx-auto max-w-7xl px-4">
                    <h2 class="text-2xl md:text-3xl font-semibold">How it works</h2>
                    <div class="mt-8 grid md:grid-cols-3 gap-6">
                        @php $steps = [
            ['n' => '01','t' => 'Connect','d' => 'Onboard fleets, merchants, and roles securely.'],
            ['n' => '02','t' => 'Control','d' => 'Define policies, limits, and approval flows.'],
            ['n' => '03','t' => 'Optimize','d' => 'Monitor spend and improve ops with insights.'],
          ]; @endphp
                        @foreach($steps as $s)
                            <div class="p-6 rounded-2xl border border-gray-200">
                                <div class="text-xs tracking-widest text-gray-400">STEP {{ $s['n'] }}</div>
                                <h3 class="mt-2 font-medium">{{ $s['t'] }}</h3>
                                <p class="mt-2 text-sm text-gray-600">{{ $s['d'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <!-- Security -->
            <section id="security" class="py-16">
                <div class="container mx-auto max-w-7xl px-4 grid md:grid-cols-2 gap-10 items-center">
                    <div class="p-8 rounded-2xl bg-[#0B1D3A] text-white">
                        <h3 class="text-xl font-medium">Enterprise-grade security</h3>
                        <ul class="mt-4 space-y-2 text-sm text-white/90 list-disc pl-5">
                            <li>SSO (OpenID/OAuth2) and RBAC</li>
                            <li>Audit trails for every action</li>
                            <li>Encrypted at rest and in transit</li>
                        </ul>
                    </div>
                    <div class="text-gray-600 text-sm">
                        Minimal footprint, maximum control. Komiut keeps your financial data safe while giving teams exactly the access they need.
                    </div>
                </div>
            </section>

            <!-- CTA -->
            <section class="py-16 border-t border-gray-100">
                <div class="container mx-auto max-w-7xl px-4 text-center">
                    <h3 class="text-2xl md:text-3xl font-semibold">Transform how you manage fleet spend</h3>
                    <div class="mt-6 flex gap-3 justify-center">
                        <a href="{{ url('/request-access') }}" class="px-5 py-3 rounded-xl bg-[#2E86DE] text-white">Request access</a>
                        <a href="{{ route('login') }}" class="px-5 py-3 rounded-xl border border-gray-300">Sign in</a>
                    </div>
                </div>
            </section>

            <!-- Footer -->
            <footer class="py-10 text-sm text-gray-500">
                <div class="container mx-auto max-w-7xl px-4 flex flex-col md:flex-row items-center justify-between gap-4">
                    <div>© {{ date('Y') }} Komiut. All rights reserved.</div>
                    <div class="flex gap-6">
                        <a href="{{ url('/privacy') }}" class="hover:text-gray-700">Privacy</a>
                        <a href="{{ url('/terms') }}" class="hover:text-gray-700">Terms</a>
                        <a href="{{ url('/security') }}" class="hover:text-gray-700">Security</a>
                    </div>
                </div>
            </footer>
        </div>
        </div>

    @elseif(Str::contains($host, '2safiri.co.ke'))
        {{-- ================= 2SAFIRI: Friendly Minimal ================= --}}
        <div class='container-fluid bg' id='home'>

        <div class="min-h-screen bg-white text-[#333]">
            <!-- Nav -->
            <header class="sticky top-0 z-30 bg-white/80 backdrop-blur border-b border-gray-100">
                <div class="container mx-auto max-w-7xl px-4 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <img src="{{ asset('images/2safiri-logo.svg') }}" alt="2Safiri" class="h-7 w-auto"/>
                        <span class="sr-only">2Safiri</span>
                    </div>
                    <nav class="hidden md:flex items-center gap-8 text-sm">
                        <a href="#benefits" class="hover:text-[#00BFA6]">Benefits</a>
                        <a href="#usecases" class="hover:text-[#00BFA6]">Use cases</a>
                        <a href="#screens" class="hover:text-[#00BFA6]">Screens</a>
                    </nav>
                    <div class="hidden md:flex items-center gap-3">
                        <a href="{{ route('login') }}" class="text-sm hover:text-[#00BFA6]">Log in</a>
                        <a href="{{ url('/app') }}" class="text-sm px-4 py-2 rounded-xl bg-[#00BFA6] text-white hover:opacity-90">Get the app</a>
                    </div>
                </div>
            </header>

            <!-- Hero -->
            <section class="relative overflow-hidden">
                <div class="absolute inset-0 bg-gradient-to-b from-white to-teal-50"></div>
                <div class="relative container mx-auto max-w-7xl px-4 py-18 md:py-24 grid md:grid-cols-2 gap-12 items-center">
                    <div>
                        <h1 class="text-4xl md:text-5xl font-semibold leading-tight tracking-tight">
                            Keep Your Fleet Moving — <span class="text-[#00BFA6]">Without Money Headaches</span>
                        </h1>
                        <p class="mt-5 text-gray-600 max-w-xl">Track fuel usage, control limits, and get instant alerts. Built for operators, owners, and drivers.</p>
                        <div class="mt-8 flex flex-wrap gap-3">
                            <a href="#benefits" class="px-5 py-3 rounded-xl bg-[#00BFA6] text-white hover:opacity-90">Start for free</a>
                            <a href="#usecases" class="px-5 py-3 rounded-xl border border-gray-300">See how it works</a>
                        </div>
                    </div>
                    <div class="md:pl-8">
                        <div class="aspect-[9/16] md:aspect-[16/10] rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                            <img src="{{ asset('images/hero-app-mobile.png') }}" alt="2Safiri app screens" class="w-full h-full object-cover"/>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Benefits -->
            <section id="benefits" class="py-14 md:py-20">
                <div class="container mx-auto max-w-7xl px-4">
                    <div class="max-w-2xl">
                        <h2 class="text-2xl md:text-3xl font-semibold">Why 2Safiri</h2>
                        <p class="mt-2 text-gray-600">Simple tools that keep vehicles fueled and budgets in check.</p>
                    </div>
                    <div class="mt-10 grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                        @php $benefits = [
            ['title' => 'Fuel Limit Tracking','desc' => 'Set and enforce per-vehicle or driver limits.'],
            ['title' => 'Balance & Alerts','desc' => 'Instant SMS/email alerts for top-ups and spend.'],
            ['title' => 'Fast Approvals','desc' => 'Approve or decline requests on mobile.'],
            ['title' => 'Spending Reports','desc' => 'Understand trends and reduce leakage.'],
            ['title' => 'Station Controls','desc' => 'Whitelist stations and permitted products.'],
            ['title' => 'Integrations','desc' => 'Connect to your tools via API.'],
          ]; @endphp
                        @foreach($benefits as $b)
                            <div class="p-6 rounded-2xl border border-gray-200 hover:shadow-sm transition">
                                <h3 class="font-medium">{{ $b['title'] }}</h3>
                                <p class="mt-2 text-sm text-gray-600">{{ $b['desc'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <!-- Use cases -->
            <section id="usecases" class="py-14 md:py-20 border-t border-gray-100">
                <div class="container mx-auto max-w-7xl px-4">
                    <h2 class="text-2xl md:text-3xl font-semibold">Built for your fleet</h2>
                    <div class="mt-8 grid md:grid-cols-3 gap-6">
                        @php $cases = [
            ['t' => 'Bus operators','d' => 'Control route-based fueling and daily limits.'],
            ['t' => 'Ride-hailing','d' => 'Per-shift limits and real-time approvals.'],
            ['t' => 'Delivery fleets','d' => 'Track spend per route and reduce downtime.'],
          ]; @endphp
                        @foreach($cases as $c)
                            <div class="p-6 rounded-2xl border border-gray-200">
                                <h3 class="font-medium">{{ $c['t'] }}</h3>
                                <p class="mt-2 text-sm text-gray-600">{{ $c['d'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <!-- Screens -->
            <section id="screens" class="py-16">
                <div class="container mx-auto max-w-7xl px-4 grid md:grid-cols-2 gap-8 items-center">
                    <div class="aspect-[4/3] rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                        <img src="{{ asset('images/screen-report.png') }}" alt="Reports" class="w-full h-full object-cover"/>
                    </div>
                    <div class="aspect-[4/3] rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                        <img src="{{ asset('images/screen-approvals.png') }}" alt="Approvals" class="w-full h-full object-cover"/>
                    </div>
                </div>
            </section>

            <!-- CTA -->
            <section class="py-16 border-t border-gray-100 bg-gradient-to-b from-white to-teal-50">
                <div class="container mx-auto max-w-7xl px-4 text-center">
                    <h3 class="text-2xl md:text-3xl font-semibold">Join fleets saving time & money with 2Safiri</h3>
                    <div class="mt-6 flex gap-3 justify-center">
                        <a href="{{ url('/signup') }}" class="px-5 py-3 rounded-xl bg-[#00BFA6] text-white">Create account</a>
                        <a href="{{ route('login') }}" class="px-5 py-3 rounded-xl border border-gray-300">Log in</a>
                    </div>
                </div>
            </section>

            <!-- Footer -->
            <footer class="py-10 text-sm text-gray-500">
                <div class="container mx-auto max-w-7xl px-4 flex flex-col md:flex-row items-center justify-between gap-4">
                    <div>© {{ date('Y') }} 2Safiri. All rights reserved.</div>
                    <div class="flex gap-6">
                        <a href="{{ url('/privacy') }}" class="hover:text-gray-700">Privacy</a>
                        <a href="{{ url('/terms') }}" class="hover:text-gray-700">Terms</a>
                        <a href="{{ url('/help') }}" class="hover:text-gray-700">Help</a>
                    </div>
                </div>
            </footer>
        </div>
        </div>

    @else
        {{-- ================ DEFAULT (fallback) ================ --}}
        <div class='container-fluid bg' id='home'>

        <div class="min-h-screen grid place-items-center bg-gray-50">
            <div class="text-center px-6">
                <h1 class="text-3xl font-semibold">Fleet Admin</h1>
                <p class="mt-2 text-gray-600">Manage vehicle expenses, fuel limits, and approvals from one dashboard.</p>
                <div class="mt-6 flex gap-3 justify-center">
                    <a href="{{ route('login') }}" class="px-5 py-3 rounded-xl bg-black text-white">Sign in</a>
                    <a href="{{ url('/') }}" class="px-5 py-3 rounded-xl border border-gray-300">Learn more</a>
                </div>
            </div>
        </div>
        </div>
    @endif
@endsection
