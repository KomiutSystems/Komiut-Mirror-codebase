<!doctype html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">

<?php
    $host = request()->getHost();
    $isTwoSafiri = Str::contains($host, '2safiri.co.ke');
?>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <?php if($isTwoSafiri): ?>
        <link rel="shortcut icon" href="<?php echo e(asset('/images/logos/2safiri.png')); ?>">
    <?php else: ?>
        <link rel="shortcut icon" href="<?php echo e(asset('/images/logos/white-logo.png')); ?>">
    <?php endif; ?>

    <!-- CSRF Token -->
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

    <?php if($isTwoSafiri): ?>
        <title> 2Safiri </title>
    <?php else: ?>
        <title><?php echo e(config('app.name', 'Komiut')); ?></title>
    <?php endif; ?>


        <!-- Fonts -->
    <link rel="dns-prefetch" href="//fonts.bunny.net">
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Poppins:300,400,400i,700&display=fallback">
    <link href='<?php echo e(asset('fontawesome-free-6.4.0-web/css/all.css')); ?>' rel="stylesheet">

    <!-- css -->
    <?php if($isTwoSafiri): ?>
        <link href="<?php echo e(asset('css/2safiri/app.css')); ?>" rel="stylesheet">
        <link href="<?php echo e(asset('css/2safiri/styles.css')); ?>" rel="stylesheet">

    <?php else: ?>
        <link href="<?php echo e(asset('css/app.css')); ?>" rel="stylesheet">
        <link href="<?php echo e(asset('css/styles.css')); ?>" rel="stylesheet">

    <?php endif; ?>

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />

    <!--datatables-->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.1/css/dataTables.bootstrap5.min.css">
</head>

<body>
    <div id="app">
        <nav class="navbar navbar-expand-md navbar-light bg-white shadow-lg fixed-top">
            <div class="container">
                <a class="navbar-brand" href="<?php echo e(url('/')); ?>">
                    <?php if($isTwoSafiri): ?>
                        <img src="<?php echo e(asset('/images/logos/2safiri.png')); ?>" width='60'>
                    <?php else: ?>
                        <img src="<?php echo e(asset('/images/logos/colored-name.png')); ?>" width='150'>
                    <?php endif; ?>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
                    aria-expanded="false" aria-label="<?php echo e(__('Toggle navigation')); ?>">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <!-- Left Side Of Navbar -->
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo e(url('/')); ?>#home"><?php echo e(__('Home')); ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo e(url('/')); ?>#services"><?php echo e(__('Our Services')); ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#"><?php echo e(__('About Us')); ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#"><?php echo e(__('Contact Us')); ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#"><?php echo e(__('Privacy Policy')); ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo e(url('termini')); ?>"><?php echo e(__('Termini')); ?></a>
                        </li>
                    </ul>

                    <!-- Right Side Of Navbar -->
                    <ul class="navbar-nav ms-auto">
                        <!-- Authentication Links -->
                        <?php if(auth()->guard()->guest()): ?>
                            <?php if(Route::has('register')): ?>
                                <li class="nav-item">
                                    <a class="nav-link" href="<?php echo e(route('register')); ?>"><?php echo e(__('Register')); ?></a>
                                </li>
                            <?php endif; ?>
                            <?php if(Route::has('login')): ?>
                                <li class="nav-item">
                                    <a class="btn btn-primary" href="<?php echo e(route('login')); ?>"><i
                                            class="fa-solid fa-right-to-bracket"></i> <?php echo e(__('Login')); ?></a>
                                </li>
                            <?php endif; ?>
                        <?php else: ?>
                            <li class="nav-item">
                                <a class="btn btn-primary" href="<?php echo e(route('home')); ?>"><i
                                        class="fa-solid fa-arrow-right"></i> <?php echo e(__('Dashboard')); ?></a>
                            </li>
                            <!--
                                <li class="nav-item dropdown">
                                    <a id="navbarDropdown" class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" v-pre>
                                        <?php echo e(Auth::user()->firstname); ?> <?php echo e(Auth::user()->lastname); ?>

                                    </a>

                                    <div class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                        <a class="dropdown-item" href="<?php echo e(route('logout')); ?>"
                                           onclick="event.preventDefault();
                                                     document.getElementById('logout-form').submit();">
                                            <?php echo e(__('Logout')); ?>

                                        </a>

                                        <form id="logout-form" action="<?php echo e(route('logout')); ?>" method="POST" class="d-none">
                                            <?php echo csrf_field(); ?>
                                        </form>
                                    </div>
                                </li>-->
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </nav>

        <main>
            <?php echo $__env->yieldContent('content'); ?>
        </main>
    </div>
    <script src="<?php echo e(asset('js/app.js')); ?>"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"
        integrity="sha256-2Pmvv0kuTBOenSvLm6bvfBSSHrUJ+3A7x6P5Ebd07/g=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <!--Datatables-->
    <script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.1/js/dataTables.bootstrap5.min.js"></script>
    <?php echo $__env->yieldPushContent('js'); ?>
</body>

</html>
<?php /**PATH /var/www/resources/views/layouts/app.blade.php ENDPATH**/ ?>