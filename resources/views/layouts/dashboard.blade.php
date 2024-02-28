<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset('/images/logos/white-logo.png') }}">


    <title>{{ config('app.name', 'Laravel') }} | Portal</title>

    <!-- Google Font: Source Sans Pro -->
    <!--<link rel="stylesheet"
        href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">-->

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Fira+Sans:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Hind+Siliguri:wght@300;400;500;600;700&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Quicksand:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="{{ asset('dashboard/plugins/fontawesome-free/css/all.min.css') }}">
    <!-- Ionicons -->
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <!--Select2 css-->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- bootstrap 5-->
    <link rel='stylesheet' href="{{ asset('css/app.css') }}">

    <!-- include summernote css -->
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote.min.css" rel="stylesheet">

    <!-- iCheck -->
    <link rel="stylesheet" href="{{ asset('dashboard/plugins/icheck-bootstrap/icheck-bootstrap.min.css') }}">
    <!-- JQVMap -->
    <link rel="stylesheet" href="{{ asset('dashboard/plugins/jqvmap/jqvmap.min.css') }}">
    <!-- Theme style -->
    <link rel="stylesheet" href="{{ asset('dashboard/dist/css/adminlte.min.css') }}">
    <!-- overlayScrollbars -->
    <link rel="stylesheet" href="{{ asset('dashboard/plugins/overlayScrollbars/css/OverlayScrollbars.min.css') }}">
    <!-- Daterange picker -->
    <link rel="stylesheet" href="{{ asset('dashboard/plugins/daterangepicker/daterangepicker.css') }}">
    <!-- summernote -->
    <link rel="stylesheet" href="{{ asset('dashboard/plugins/summernote/summernote-bs4.min.css') }}">
    <!--datatables-->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.1/css/dataTables.bootstrap5.min.css">
    <!--datetime picker-->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!--croppie-->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css" />
    <!--custom css-->
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}" />
</head>

<body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">

        <!-- Preloader -->
        <div class="preloader flex-column justify-content-center align-items-center">
            <img class="animation__shake" src="{{ asset('/images/logos/white-logo.png') }}" alt="Komiut Logo"
                height="60" width="60">
        </div>

        <!-- Navbar -->
        <nav class="main-header navbar navbar-expand navbar-white navbar-light">
            <!-- Left navbar links -->
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link toggleMenu" data-widget="pushmenu" href="#" role="button"><i
                            class="fas fa-bars"></i></a>
                </li>
            </ul>

            <!-- Right navbar links -->

            <ul class="navbar-nav ml-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link profile" data-toggle="dropdown" href="#">
                        <div class='user-panel d-flex'>
                            <div class='image'>
                                <img src='{{ Auth::user()->image != '' ? asset('images/profiles/' . Auth::user()->image) : asset('images/male_avatar.svg') }}'
                                    class="img-circle elevation-1">
                            </div>
                            <div class='info'>
                                <span class='d-block text-primary'>{{ \Auth::user()->firstname }}
                                    {{ \Auth::user()->lastname }}</span>
                            </div>
                            <!--<span class="badge badge-warning navbar-badge">15</span>-->
                        </div>
                    </a>
                    <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                        <span class="dropdown-item dropdown-header">{{ \Auth::user()->firstname }}
                            {{ \Auth::user()->lastname }}</span>
                        <div class="dropdown-divider"></div>
                        <a href="{{ url('profile') }}" class="dropdown-item">
                            <i class="fas fa-user-circle mr-2"></i> My Profile
                            <!--<span class="float-right text-muted text-sm">3 mins</span>-->
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="{{ route('logout') }}"
                            onclick="event.preventDefault();
                                  document.getElementById('logout-form').submit();">
                            <i class="fas fa-power-off mr-2"></i> Logout
                            <!--<span class="float-right text-muted text-sm">3 mins</span>-->
                        </a>
                    </div>
                </li>
                <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                    @csrf
                </form>
            </ul>
        </nav>
        <!-- /.navbar -->

        <!-- Main Sidebar Container -->
        <aside class="main-sidebar sidebar-dark-primary elevation-4">
            <!-- Brand Logo -->
            <a href="index3.html" class="brand-link">
                <img src="{{ asset('/images/logos/black-logo.png') }}" alt="Komiut Logo"
                    class="brand-image img-circle elevation-3" style="opacity: .8">
                <span class="brand-text font-weight-light">{{ config('app.name', 'Laravel') }}</span>
            </a>

            <!-- Sidebar -->
            <div class="sidebar">

                <!-- Sidebar Menu -->
                <nav class="mt-2">
                    <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu"
                        data-accordion="false">
                        <!-- Add icons to the links using the .nav-icon class
                         with font-awesome or any other icon font library -->

                        <li class="nav-item">
                            <a href="{{ url('home') }}"
                                class="nav-link {{ Request::is('home') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-th"></i>
                                <p>
                                    Dashboard
                                </p>
                            </a>
                        </li>
                        @can('View Transactions')
                            <li class="nav-item {{ Request::is('transactions*') ? 'menu-open' : '' }}">
                                <a href="#" class="nav-link {{ Request::is('transactions*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-coins"></i>
                                    <p>
                                        Transactions
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    <li class="nav-item">
                                        <a href="{{ url('transactions/all') }}"
                                            class="nav-link {{ Request::is('transactions/all') ? 'active' : '' }}">
                                            <i class="far fa-circle nav-icon"></i>
                                            <p>All</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="{{ url('transactions/mpesa') }}"
                                            class="nav-link {{ Request::is('transactions/mpesa') ? 'active' : '' }}">
                                            <i class="far fa-circle nav-icon"></i>
                                            <p>Mpesa</p>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="{{ url('transactions/cash') }}"
                                            class="nav-link {{ Request::is('transactions/cash') ? 'active' : '' }}">
                                            <i class="far fa-circle nav-icon"></i>
                                            <p>Cash</p>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        @endcan
                        @can('View Summaries')
                            <li class="nav-item">
                                <a href="{{ url('summaries') }}" class="nav-link {{ Request::is('summaries*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-chart-line"></i>
                                    <p>
                                        Summaries
                                    </p>
                                </a>
                            </li>
                        @endcan
                        @if (auth()->user()->can('View Routes') ||
                                auth()->user()->can('View Places') ||
                                auth()->user()->can('View Termini') ||
                                auth()->user()->can('View Termini Users'))
                            <li class="nav-item {{ Request::is('routes*') ? 'menu-open' : '' }}">
                                <a href="#" class="nav-link {{ Request::is('routes*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-route"></i>
                                    <p>
                                        Routes
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    @can('View Places')
                                        <li class="nav-item">
                                            <a href="{{ url('routes/places') }}"
                                                class="nav-link {{ Request::is('routes/places') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Places</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Routes')
                                        <li class="nav-item">
                                            <a href="{{ url('routes') }}"
                                                class="nav-link {{ Request::is('routes') || Request::is('routes/view*') || Request::is('routes/stages*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Routes</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Termini')
                                        <li class="nav-item">
                                            <a href="{{ url('routes/termini') }}"
                                                class="nav-link {{ Request::is('routes/termini') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Termini</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Termini Users')
                                        <li class="nav-item">
                                            <a href="{{ url('routes/termini/users') }}"
                                                class="nav-link {{ Request::is('routes/termini/users') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Termini Users</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Termini Saccos')
                                        <li class="nav-item">
                                            <a href="{{ url('routes/termini/saccos') }}"
                                                class="nav-link {{ Request::is('routes/termini/saccos') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Saccos Termini</p>
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endif
                        @if (auth()->user()->can('View Queues') ||
                                auth()->user()->can('View Queue Statuses'))
                            <li class="nav-item {{ Request::is('queues*') ? 'menu-open' : '' }}">
                                <a href="#" class="nav-link {{ Request::is('queues*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-flag"></i>
                                    <p>
                                        Queues
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    @can('View Queues')
                                        <li class="nav-item">
                                            <a href="{{ url('queues/all') }}"
                                                class="nav-link {{ Request::is('queues/all') || Request::is('queues/view*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Queues</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Queue Statuses')
                                        <li class="nav-item">
                                            <a href="{{ url('queues/statuses') }}"
                                                class="nav-link {{ Request::is('queues/statuses') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Queues Statuses</p>
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endif
                        @if (auth()->user()->can('View Saccos') ||
                                auth()->user()->can('View Sacco Members') ||
                                auth()->user()->can('View Sacco Routes') ||
                                auth()->user()->can('View Sacco Vehicles'))
                            <li class="nav-item {{ Request::is('saccos*') ? 'menu-open' : '' }}">
                                <a href="#" class="nav-link {{ Request::is('saccos*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-user-shield"></i>
                                    <p>
                                        Saccos
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    @can('View Saccos')
                                        <li class="nav-item">
                                            <a href="{{ url('saccos/all') }}"
                                                class="nav-link {{ Request::is('saccos/all') || Request::is('saccos/view*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Saccos</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Sacco Members')
                                        <li class="nav-item">
                                            <a href="{{ url('saccos/members') }}"
                                                class="nav-link {{ Request::is('saccos/members') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Members</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Sacco Vehicles')
                                        <li class="nav-item">
                                            <a href="{{ url('saccos/vehicles') }}"
                                                class="nav-link {{ Request::is('saccos/vehicles') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Vehicles</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Sacco Routes')
                                        <li class="nav-item">
                                            <a href="{{ url('saccos/routes') }}"
                                                class="nav-link {{ Request::is('saccos/routes') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Routes</p>
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endif
                        @if (auth()->user()->can('View Vehicles') ||
                                auth()->user()->can('View Vehicle Users') ||
                                auth()->user()->can('View Vehicle Locations') ||
                                auth()->user()->can('View Seat Settings')||
                                auth()->user()->can('View Direct Line Claims'))
                            <li class="nav-item {{ Request::is('vehicles*') ? 'menu-open' : '' }}">

                                <a href="#" class="nav-link {{ Request::is('vehicles*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-bus"></i>
                                    <p>
                                        Vehicles
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    @can('View Vehicles')
                                        <li class="nav-item">
                                            <a href="{{ url('vehicles/all') }}"
                                                class="nav-link {{ Request::is('vehicles/all') || Request::is('vehicles/view*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Vehicles</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Vehicle Users')
                                        <li class="nav-item">
                                            <a href="{{ url('vehicles/users') }}"
                                                class="nav-link {{ Request::is('vehicles/users*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Vehicle Users</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Vehicle Locations')
                                        <li class="nav-item">
                                            <a href="{{ url('vehicles/locations') }}"
                                                class="nav-link {{ Request::is('vehicles/locations') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Vehicle Locations</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Seat Settings')
                                        <li class="nav-item">
                                            <a href="{{ url('vehicles/seats/settings') }}"
                                                class="nav-link {{ Request::is('vehicles/seats/settings') || Request::is('vehicles/seats/settings*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Seat Settings</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Direct Line Claims')
                                        <li class="nav-item">
                                            <a href="{{ url('vehicles/direct_line_claims') }}"
                                                class="nav-link {{ Request::is('vehicles/direct_line_claims') || Request::is('vehicles/direct_line_claims*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Direct Line Claims</p>
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endif
                        @if (auth()->user()->can('View Passengers') ||
                                auth()->user()->can('View Parcels'))
                            <li class="nav-item {{ Request::is('bookings*') ? 'menu-open' : '' }}">
                                <a href="#" class="nav-link {{ Request::is('bookings*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-ticket-alt"></i>
                                    <p>
                                        Bookings
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    @can('View Passengers')
                                        <li class="nav-item">
                                            <a href="{{ url('bookings/passengers') }}"
                                                class="nav-link {{ Request::is('bookings/passengers') || Request::is('bookings/passengers*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Passengers</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Parcels')
                                        <li class="nav-item">
                                            <a href="{{ url('bookings/parcels') }}"
                                                class="nav-link {{ Request::is('bookings/parcels') || Request::is('bookings/parcels*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Parcels</p>
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endif
                        <li class="nav-item">
                            <a href="{{ url('points') }}"
                                class="nav-link {{ Request::is('points') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-star-half-alt"></i>
                                <p>
                                    Points
                                </p>
                            </a>
                        </li>
                        @if (auth()->user()->can('View Users') ||
                                auth()->user()->can('View Roles') ||
                                auth()->user()->can('View Permissions'))
                            <li class="nav-item {{ Request::is('users*') ? 'menu-open' : '' }}">
                                <a href="#" class="nav-link {{ Request::is('users*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-users"></i>
                                    <p>
                                        Users
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    @can('View Users')
                                        <li class="nav-item">
                                            <a href="{{ url('users/all') }}"
                                                class="nav-link {{ Request::is('users/all') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Users</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Roles')
                                        <li class="nav-item">
                                            <a href="{{ url('users/roles') }}"
                                                class="nav-link {{ Request::is('users/roles*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Roles</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Permissions')
                                        <!--<li class="nav-item">
                                    <a href="{{ url('users/permissions') }}"
                                       class="nav-link {{ Request::is('users/permissions') ? 'active' : '' }}">
                                        <i class="far fa-circle nav-icon"></i>
                                        <p>Permissions</p>
                                    </a>
                                </li>-->
                                    @endcan
                                </ul>
                            </li>
                        @endcan
                        @if (auth()->user()->can('View Crews'))
                            <li class="nav-item">
                                <a href="{{ url('crews') }}" class="nav-link {{ Request::is('crews*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-user-cog"></i>
                                    <p>
                                        Crews
                                    </p>
                                </a>
                            </li>
                        @endcan
                        @if (auth()->user()->can('View Payment Settings') ||
                                auth()->user()->can('View Gender Settings') ||
                                auth()->user()->can('View Point Settings')||
                                auth()->user()->can('View Services Settings'))
                            <li class="nav-item {{ Request::is('settings*') ? 'menu-open' : '' }}">
                                <a href="#"
                                    class="nav-link {{ Request::is('settings*') ? 'active' : '' }}">
                                    <i class="nav-icon fas fa-cog"></i>
                                    <p>
                                        Settings
                                        <i class="right fas fa-angle-left"></i>
                                    </p>
                                </a>
                                <ul class="nav nav-treeview">
                                    @can('View Gender Settings')
                                        <li class="nav-item">
                                            <a href="{{ url('settings/gender') }}"
                                                class="nav-link {{ Request::is('settings/gender') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Gender</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Payment Settings')
                                        <li class="nav-item">
                                            <a href="{{ url('settings/mpesa') }}"
                                                class="nav-link {{ Request::is('settings/mpesa*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Mpesa Settings</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Point Settings')
                                        <li class="nav-item">
                                            <a href="{{ url('settings/points') }}"
                                                class="nav-link {{ Request::is('settings/points*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Points Settings</p>
                                            </a>
                                        </li>
                                    @endcan
                                    @can('View Services Settings')
                                        <li class="nav-item">
                                            <a href="{{ url('settings/services') }}"
                                                class="nav-link {{ Request::is('settings/services*') ? 'active' : '' }}">
                                                <i class="far fa-circle nav-icon"></i>
                                                <p>Services Settings</p>
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endcan
                        <li class="nav-item">
                            <a href="{{ url('profile') }}"
                                class="nav-link {{ Request::is('profile') ? 'active' : '' }}">
                                <i class="nav-icon fas fa-user"></i>
                                <p>
                                    Profile
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('logout') }}"
                                onclick="event.preventDefault();
                          document.getElementById('logout-form').submit();">
                                <i class="nav-icon fas fa-power-off"></i>
                                <p>Logout</p>
                            </a>
                        </li>
            </ul>
        </nav>
        <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
</aside>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    @yield('content')
</div>
<!-- /.content-wrapper -->
<footer class="main-footer">
    <strong> {{ config('app.name', 'Laravel') }}&copy; {{ date('Y') }}</strong>
    All rights reserved.
    <div class="float-right d-none d-sm-inline-block">
        <b>Version</b> 1
    </div>
</footer>

<!-- Control Sidebar -->
<aside class="control-sidebar control-sidebar-dark">
    <!-- Control sidebar content goes here -->
</aside>
<!-- /.control-sidebar -->
</div>
<!-- ./wrapper -->

<!-- jQuery -->
<script src="{{ asset('dashboard/plugins/jquery/jquery.min.js') }}"></script>
<!-- select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!--Datatables-->
<script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.1/js/dataTables.bootstrap5.min.js"></script>
<!--fixed columns datatable-->
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>
<!-- jQuery UI 1.11.4 -->
<script src="{{ asset('dashboard/plugins/jquery-ui/jquery-ui.min.js') }}"></script>
<!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
<script>
    $.widget.bridge('uibutton', $.ui.button)
</script>
<!-- Bootstrap 4 -->
<script src="{{ asset('dashboard/plugins/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<!-- ChartJS -->
<script src="{{ asset('dashboard/plugins/chart.js/Chart.min.js') }}"></script>
<!-- jQuery Knob Chart -->
<script src="{{ asset('dashboard/plugins/jquery-knob/jquery.knob.min.js') }}"></script>
<!-- daterangepicker -->
<script src="{{ asset('dashboard/plugins/moment/moment.min.js') }}"></script>
<script src="{{ asset('dashboard/plugins/daterangepicker/daterangepicker.js') }}"></script>
<!-- Tempusdominus Bootstrap 4 -->
<script src="{{ asset('dashboard/plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js') }}"></script>
<!-- Summernote -->
<script src="{{ asset('dashboard/plugins/summernote/summernote-bs4.min.js') }}"></script>
<!-- Summernote JS-->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote.min.js"></script>
<!-- overlayScrollbars -->
<script src="{{ asset('dashboard/plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js') }}"></script>
<!-- AdminLTE App -->
<script src="{{ asset('dashboard/dist/js/adminlte.js') }}"></script>
<!-- AdminLTE for demo purposes -->
<!--<script src="{{ asset('dashboard/dist/js/demo.js') }}"></script>-->
<!-- AdminLTE dashboard demo (This is only for demo purposes) -->
<script src="{{ asset('dashboard/dist/js/pages/dashboard.js') }}"></script>
<!--datetimepicker-->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<!-- croppie js-->
<script src="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js"></script>
<script src="https://cdn.datatables.net/buttons/1.6.5/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/1.6.5/js/buttons.html5.min.js"></script>
<!--sweetalert-->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="{{ asset('js/notify.js') }}"></script>
@stack('js')
<script>
    $(document).ready(function() {
        //localStorage.setItem('toggleMenu', 1);
        //localStorage.removeItem('myCat');
        //localStorage.clear();
        let menu = localStorage.getItem('toggleMenu');
        if (menu != null) {
            if (menu == 1) {
                $('body').addClass('sidebar-collapse');
            } else {
                $('body').removeClass('sidebar-collapse');
            }
        }
        $('.toggleMenu').click(function() {
            if (menu == 1) {
                menu = 0;
            } else if (menu == 0) {
                menu = 1;
            } else {
                menu = 1;
            }
            localStorage.setItem('toggleMenu', menu);
        });
        setInterval(() => {
            checkLogin();
        }, 5000);

        function checkLogin() {
            $.ajax({
                url: '{{ url('check-login') }}',
                method: 'GET',
                success: function(response) {
                    if (!response.loggedIn) {
                        // User is not logged in
                        location.href = "{{ url('/login') }}";
                    }
                },
                /*
                                error: function() {
                                    // Error handling
                                    console.log('Error checking login status');
                                }*/
            });
        }
    });
</script>
</body>

</html>
