<?php $__env->startSection('content'); ?>
    <!-- Hero Section -->
    <section class="hero d-flex align-items-center text-light" style="background: linear-gradient(135deg, #1e3c72, #2a5298); min-height: 80vh;">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 text-center text-lg-start">
                    <?php $host = request()->getHost(); ?>

                    <?php if(Str::contains($host, 'komiut.com')): ?>
                        <h1 class="display-4 fw-bold">Fleet Management Made Simple</h1>
                        <h2 class="display-2 fw-bold text-gradient">Optimize Your Operations</h2>
                        <p class="lead mt-4">Komiut Fleet Admin gives you full control over vehicle expenses, fuel management, and operational insights, making fleet oversight seamless and efficient.</p>
                        <a href="#services" class="btn btn-primary btn-lg mt-4">Explore Komiut Features</a>

                    <?php elseif(Str::contains($host, '2safiri.co.ke')): ?>
                        <h1 class="display-4 fw-bold">Streamline Your Fleet Operations</h1>
                        <h2 class="display-2 fw-bold text-gradient">Manage, Monitor, Succeed</h2>
                        <p class="lead mt-4">2Safiri Fleet Admin helps you track vehicle spend, approve fuel transactions, and generate detailed reports—all from one central dashboard.</p>
                        <a href="#services" class="btn btn-primary btn-lg mt-4">Explore 2Safiri Features</a>

                    <?php else: ?>
                        <h1 class="display-4 fw-bold">Manage Your Fleet Finances Effortlessly</h1>
                        <h2 class="display-2 fw-bold text-gradient">Control, Track & Optimize</h2>
                        <p class="lead mt-4">Our Fleet Admin platform gives you real-time control over vehicle expenses, fueling, and payment workflows, helping you optimize fleet operations efficiently.</p>
                        <a href="#services" class="btn btn-primary btn-lg mt-4">Explore Features</a>
                    <?php endif; ?>
                </div>

                <div class="col-lg-6 text-center mt-5 mt-lg-0">
                    <img src="<?php echo e(asset('images/hero-services.jpg')); ?>" alt="Dashboard Illustration" class="img-fluid rounded shadow-lg animate__animated animate__fadeInRight">
                </div>
            </div>
        </div>
    </section>

    <!-- Features / Services Section -->
    <section id="services" class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold">Core <span class="text-primary">Features</span></h2>
                <p class="text-muted fs-5">Tools to Manage Your Fleet & Finances Efficiently</p>
            </div>

            <div class="row g-4">
                <?php $__currentLoopData = $services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="col-md-6 col-lg-4">
                        <a href="<?php echo e(url('services/view/'.$service->id)); ?>" class="text-decoration-none">
                            <div class="card h-100 shadow-sm border-0 hover-shadow">
                                <img src="<?php echo e($service->image != '' ? asset('images/services/' . $service->image) : asset('images/image.png')); ?>" class="card-img-top" alt="<?php echo e($service->name); ?>">
                                <div class="card-body">
                                    <h5 class="card-title fw-bold text-dark"><?php echo e($service->name); ?></h5>
                                    <p class="card-text text-muted"><?php echo e(\Str::words(strip_tags($service->description), 20, '...')); ?></p>
                                </div>
                                <div class="card-footer bg-transparent border-0">
                                    <button class="btn btn-outline-primary w-100">View Details</button>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </div>
    </section>

    <!-- Call to Action Section -->
    <section class="py-5 text-light" style="background: linear-gradient(135deg, #2a5298, #1e3c72);">
        <div class="container text-center">
            <h2 class="fw-bold mb-3">Take Full Control of Your Fleet</h2>
            <p class="fs-5 mb-4">Monitor expenses, approve fuel transactions, generate reports, and streamline operations with one central platform.</p>
            <a href="#contact" class="btn btn-lg btn-primary">Start Managing Today</a>
        </div>
    </section>

    <style>
        .hover-shadow:hover {
            transform: translateY(-8px);
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15) !important;
        }
        .text-gradient {
            background: linear-gradient(to right, #00c6ff, #0072ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH /var/www/resources/views/index.blade.php ENDPATH**/ ?>