<?php
// force-https.php - Simple HTTPS redirect
if ($_SERVER['HTTPS'] != "on") {
    $redirect_url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header("Location: " . $redirect_url);
    exit();
}
?>

<?php

session_start();

// Check if user is already logged in and redirect accordingly
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'admin' || $_SESSION['user_role'] === 'super_admin') {
        header("Location: admin_dashboard.php");
        exit();
    } else {
        header("Location: dashboard.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>inter ministry exchange - Government Data Exchange Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <style>
        :root {
            --primary-green: #10B981;
            --light-green: #D1FAE5;
            --dark-green: #059669;
            --darker-green: #047857;
            --bg-color: #ECFDF5;
            --text-color: #064E3B;
            --shadow-light: #F9FAFB;
            --shadow-dark: #D1D5DB;
        }
        
        body {
            background: linear-gradient(135deg, var(--bg-color) 0%, #F0FDF4 100%);
            color: var(--text-color);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }
        
        .neumorphic {
            border-radius: 20px;
            background: var(--bg-color);
            box-shadow: 12px 12px 24px #d9dfe2, 
                        -12px -12px 24px #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        
        .neumorphic-small {
            border-radius: 16px;
            background: var(--bg-color);
            box-shadow: 6px 6px 12px #d9dfe2, 
                        -6px -6px 12px #ffffff;
        }
        
        .neumorphic-btn {
            border-radius: 16px;
            background: linear-gradient(145deg, #d4f5e4, #b2e6cf);
            box-shadow: 6px 6px 12px #c4e5d5, 
                        -6px -6px 12px #f6fffb;
            transition: all 0.3s ease;
            color: var(--darker-green);
            font-weight: 600;
        }
        
        .neumorphic-btn:hover {
            box-shadow: 3px 3px 6px #c4e5d5, 
                        -3px -3px 6px #f6fffb;
            transform: translateY(2px);
        }
        
        .neumorphic-btn-secondary {
            border-radius: 16px;
            background: var(--bg-color);
            box-shadow: 6px 6px 12px #c4e5d5, 
                        -6px -6px 12px #f6fffb;
            transition: all 0.3s ease;
            color: var(--darker-green);
            font-weight: 600;
        }
        
        .neumorphic-btn-secondary:hover {
            box-shadow: 3px 3px 6px #c4e5d5, 
                        -3px -3px 6px #f6fffb;
            transform: translateY(2px);
        }
        
        .hero-fade-in {
            animation: heroFadeIn 1s ease-out;
        }
        
        .feature-slide-up {
            animation: featureSlideUp 0.8s ease-out;
        }
        
        .stagger-1 { animation-delay: 0.1s; }
        .stagger-2 { animation-delay: 0.2s; }
        .stagger-3 { animation-delay: 0.3s; }
        .stagger-4 { animation-delay: 0.4s; }
        
        @keyframes heroFadeIn {
            from { 
                opacity: 0; 
                transform: translateY(30px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        @keyframes featureSlideUp {
            from { 
                opacity: 0; 
                transform: translateY(40px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        .floating {
            animation: floating 3s ease-in-out infinite;
        }
        
        @keyframes floating {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        .pulse-glow {
            animation: pulseGlow 2s ease-in-out infinite;
        }
        
        @keyframes pulseGlow {
            0%, 100% { 
                box-shadow: 12px 12px 24px #d9dfe2, 
                            -12px -12px 24px #ffffff;
            }
            50% { 
                box-shadow: 12px 12px 24px #d9dfe2, 
                            -12px -12px 24px #ffffff,
                            0 0 20px rgba(16, 185, 129, 0.3);
            }
        }
        
        .gradient-text {
            background: linear-gradient(135deg, var(--dark-green), var(--primary-green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <div class="min-h-screen flex flex-col">
        <!-- Header -->
        <header class="p-6">
            <div class="max-w-7xl mx-auto flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="neumorphic-small p-3">
                        <i data-lucide="building-2" class="w-8 h-8 text-green-600"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold gradient-text">inter ministry exchange</h1>
                        <p class="text-sm text-green-600">Government Data Exchange</p>
                    </div>
                </div>
                
                <div class="hidden md:flex items-center space-x-4">
                    <a href="#features" class="text-green-700 hover:text-green-800 transition-colors">Features</a>
                    <a href="#security" class="text-green-700 hover:text-green-800 transition-colors">Security</a>
                    <a href="loginSignup.php" class="neumorphic-btn-secondary px-6 py-2 text-sm">
                        Sign In
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Hero Content -->
        <div class="flex-1 flex items-center justify-center px-6">
            <div class="max-w-6xl mx-auto grid lg:grid-cols-2 gap-12 items-center">
                <!-- Left Content -->
                <div class="hero-fade-in">
                    <div class="mb-6">
                        <div class="inline-flex items-center px-4 py-2 neumorphic-small text-sm text-green-700 mb-6">
                            <i data-lucide="shield-check" class="w-4 h-4 mr-2"></i>
                            Secure Government Portal
                        </div>
                    </div>
                    
                    <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-green-800 mb-6 leading-tight">
                        Connect Your
                        <span class="gradient-text block">Ministry Data</span>
                    </h1>
                    
                    <p class="text-lg text-green-600 mb-8 leading-relaxed">
                        Streamline inter-ministerial communication and data sharing with our secure, 
                        government-grade platform. Built for efficiency, designed for collaboration.
                    </p>
                    
                    <div class="flex flex-col sm:flex-row gap-4 mb-8">
                        <a href="loginSignup.php" class="neumorphic-btn px-8 py-4 text-lg inline-flex items-center justify-center">
                            <i data-lucide="log-in" class="w-5 h-5 mr-2"></i>
                            Access Portal
                        </a>
                        <button onclick="scrollToFeatures()" class="neumorphic-btn-secondary px-8 py-4 text-lg inline-flex items-center justify-center">
                            <i data-lucide="info" class="w-5 h-5 mr-2"></i>
                            Learn More
                        </button>
                    </div>
                    
                    <!-- Stats -->
                    <div class="grid grid-cols-3 gap-6 text-center">
                        <div class="neumorphic-small p-4">
                            <div class="text-2xl font-bold text-green-800">4+</div>
                            <div class="text-sm text-green-600">Ministries</div>
                        </div>
                        <div class="neumorphic-small p-4">
                            <div class="text-2xl font-bold text-green-800">1000+</div>
                            <div class="text-sm text-green-600">Users</div>
                        </div>
                        <div class="neumorphic-small p-4">
                            <div class="text-2xl font-bold text-green-800">24/7</div>
                            <div class="text-sm text-green-600">Support</div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Visual -->
                <div class="hidden lg:flex justify-center items-center">
                    <div class="relative">
                        <div class="neumorphic pulse-glow p-12 floating">
                            <div class="grid grid-cols-2 gap-6">
                                <div class="neumorphic-small p-6 text-center stagger-1 feature-slide-up">
                                    <i data-lucide="database" class="w-8 h-8 text-green-600 mx-auto mb-3"></i>
                                    <div class="text-sm font-medium text-green-800">Data Management</div>
                                </div>
                                <div class="neumorphic-small p-6 text-center stagger-2 feature-slide-up">
                                    <i data-lucide="users" class="w-8 h-8 text-green-600 mx-auto mb-3"></i>
                                    <div class="text-sm font-medium text-green-800">Collaboration</div>
                                </div>
                                <div class="neumorphic-small p-6 text-center stagger-3 feature-slide-up">
                                    <i data-lucide="shield" class="w-8 h-8 text-green-600 mx-auto mb-3"></i>
                                    <div class="text-sm font-medium text-green-800">Security</div>
                                </div>
                                <div class="neumorphic-small p-6 text-center stagger-4 feature-slide-up">
                                    <i data-lucide="activity" class="w-8 h-8 text-green-600 mx-auto mb-3"></i>
                                    <div class="text-sm font-medium text-green-800">Analytics</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Features Section -->
    <section id="features" class="py-20 px-6">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-bold text-green-800 mb-4">
                    Built for Government Efficiency
                </h2>
                <p class="text-lg text-green-600 max-w-2xl mx-auto">
                    Our platform provides the tools and security measures necessary for 
                    effective inter-ministerial collaboration and data management.
                </p>
            </div>
            
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="neumorphic p-8 text-center hover:shadow-2xl transition-all duration-300">
                    <div class="neumorphic-small w-16 h-16 mx-auto mb-6 flex items-center justify-center">
                        <i data-lucide="lock" class="w-8 h-8 text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-green-800 mb-4">End-to-End Security</h3>
                    <p class="text-green-600">
                        Military-grade encryption and multi-factor authentication protect 
                        sensitive government data at all times.
                    </p>
                </div>
                
                <!-- Feature 2 -->
                <div class="neumorphic p-8 text-center hover:shadow-2xl transition-all duration-300">
                    <div class="neumorphic-small w-16 h-16 mx-auto mb-6 flex items-center justify-center">
                        <i data-lucide="share-2" class="w-8 h-8 text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-green-800 mb-4">Seamless Sharing</h3>
                    <p class="text-green-600">
                        Share documents, data, and resources across ministries with 
                        granular permission controls and audit trails.
                    </p>
                </div>
                
                <!-- Feature 3 -->
                <div class="neumorphic p-8 text-center hover:shadow-2xl transition-all duration-300">
                    <div class="neumorphic-small w-16 h-16 mx-auto mb-6 flex items-center justify-center">
                        <i data-lucide="bar-chart" class="w-8 h-8 text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-green-800 mb-4">Real-time Analytics</h3>
                    <p class="text-green-600">
                        Monitor data usage, track collaboration metrics, and generate 
                        comprehensive reports for informed decision-making.
                    </p>
                </div>
                
                <!-- Feature 4 -->
                <div class="neumorphic p-8 text-center hover:shadow-2xl transition-all duration-300">
                    <div class="neumorphic-small w-16 h-16 mx-auto mb-6 flex items-center justify-center">
                        <i data-lucide="clock" class="w-8 h-8 text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-green-800 mb-4">24/7 Availability</h3>
                    <p class="text-green-600">
                        Access your data and collaborate with colleagues anytime, anywhere 
                        with our reliable cloud infrastructure.
                    </p>
                </div>
                
                <!-- Feature 5 -->
                <div class="neumorphic p-8 text-center hover:shadow-2xl transition-all duration-300">
                    <div class="neumorphic-small w-16 h-16 mx-auto mb-6 flex items-center justify-center">
                        <i data-lucide="settings" class="w-8 h-8 text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-green-800 mb-4">Easy Administration</h3>
                    <p class="text-green-600">
                        Intuitive admin panels make user management, permissions, 
                        and system configuration simple and efficient.
                    </p>
                </div>
                
                <!-- Feature 6 -->
                <div class="neumorphic p-8 text-center hover:shadow-2xl transition-all duration-300">
                    <div class="neumorphic-small w-16 h-16 mx-auto mb-6 flex items-center justify-center">
                        <i data-lucide="headphones" class="w-8 h-8 text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-green-800 mb-4">Dedicated Support</h3>
                    <p class="text-green-600">
                        Our government-specialized support team provides training, 
                        technical assistance, and ongoing maintenance.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Security Section -->
    <section id="security" class="py-20 px-6 bg-gradient-to-b from-transparent to-green-50">
        <div class="max-w-6xl mx-auto">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <h2 class="text-3xl md:text-4xl font-bold text-green-800 mb-6">
                        Government-Grade Security
                    </h2>
                    <p class="text-lg text-green-600 mb-8">
                        Built to meet the highest security standards required for government 
                        operations, ensuring your sensitive data remains protected.
                    </p>
                    
                    <div class="space-y-6">
                        <div class="flex items-start space-x-4">
                            <div class="neumorphic-small p-3">
                                <i data-lucide="shield-check" class="w-6 h-6 text-green-600"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-green-800 mb-2">Multi-Factor Authentication</h4>
                                <p class="text-green-600">Enhanced security with SMS, email, and app-based verification.</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start space-x-4">
                            <div class="neumorphic-small p-3">
                                <i data-lucide="key" class="w-6 h-6 text-green-600"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-green-800 mb-2">Advanced Encryption</h4>
                                <p class="text-green-600">AES-256 encryption for data at rest and in transit.</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start space-x-4">
                            <div class="neumorphic-small p-3">
                                <i data-lucide="eye" class="w-6 h-6 text-green-600"></i>
                            </div>
                            <div>
                                <h4 class="font-semibold text-green-800 mb-2">Audit Logging</h4>
                                <p class="text-green-600">Comprehensive activity tracking and compliance reporting.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-center">
                    <div class="neumorphic p-8">
                        <div class="grid grid-cols-2 gap-6">
                            <div class="text-center">
                                <div class="neumorphic-small p-6 mb-4">
                                    <i data-lucide="shield" class="w-12 h-12 text-green-600 mx-auto"></i>
                                </div>
                                <div class="text-sm font-medium text-green-800">ISO 27001</div>
                                <div class="text-xs text-green-600">Certified</div>
                            </div>
                            <div class="text-center">
                                <div class="neumorphic-small p-6 mb-4">
                                    <i data-lucide="lock" class="w-12 h-12 text-green-600 mx-auto"></i>
                                </div>
                                <div class="text-sm font-medium text-green-800">SOC 2</div>
                                <div class="text-xs text-green-600">Compliant</div>
                            </div>
                            <div class="text-center">
                                <div class="neumorphic-small p-6 mb-4">
                                    <i data-lucide="check-circle" class="w-12 h-12 text-green-600 mx-auto"></i>
                                </div>
                                <div class="text-sm font-medium text-green-800">GDPR</div>
                                <div class="text-xs text-green-600">Ready</div>
                            </div>
                            <div class="text-center">
                                <div class="neumorphic-small p-6 mb-4">
                                    <i data-lucide="server" class="w-12 h-12 text-green-600 mx-auto"></i>
                                </div>
                                <div class="text-sm font-medium text-green-800">Local</div>
                                <div class="text-xs text-green-600">Hosting</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-20 px-6">
        <div class="max-w-4xl mx-auto text-center">
            <div class="neumorphic p-12">
                <h2 class="text-3xl md:text-4xl font-bold text-green-800 mb-6">
                    Ready to Transform Your Ministry's Data Management?
                </h2>
                <p class="text-lg text-green-600 mb-8">
                    Join the digital transformation of Zambian government services. 
                    Create your account today and start collaborating more effectively.
                </p>
                
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <a href="loginSignup.php" class="neumorphic-btn px-8 py-4 text-lg inline-flex items-center justify-center">
                        <i data-lucide="user-plus" class="w-5 h-5 mr-2"></i>
                        Create Account
                    </a>
                    <a href="loginSignup.php" class="neumorphic-btn-secondary px-8 py-4 text-lg inline-flex items-center justify-center">
                        <i data-lucide="log-in" class="w-5 h-5 mr-2"></i>
                        Sign In
                    </a>
                </div>
                
                <p class="text-sm text-green-600 mt-6">
                    Need help getting started? Contact our support team at 
                    <a href="mailto:chiwayaelijah6@gmail.com" class="font-medium hover:text-green-800">chiwayaelijah6@gmail.com</a>
                </p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="py-12 px-6 border-t border-green-200">
        <div class="max-w-6xl mx-auto">
            <div class="grid md:grid-cols-4 gap-8">
                <div class="col-span-2">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="neumorphic-small p-2">
                            <i data-lucide="building-2" class="w-6 h-6 text-green-600"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold gradient-text"> inter ministry exchange</h3>
                            <p class="text-sm text-green-600">Government Data Exchange</p>
                        </div>
                    </div>
                    <p class="text-green-600 mb-4">
                        Connecting Zambian government ministries through secure, 
                        efficient data sharing and collaboration tools.
                    </p>
                    <div class="flex space-x-4">
                        <div class="neumorphic-small p-2">
                            <i data-lucide="mail" class="w-5 h-5 text-green-600"></i>
                        </div>
                        <div class="neumorphic-small p-2">
                            <i data-lucide="phone" class="w-5 h-5 text-green-600"></i>
                        </div>
                        <div class="neumorphic-small p-2">
                            <i data-lucide="map-pin" class="w-5 h-5 text-green-600"></i>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 class="font-semibold text-green-800 mb-4">Quick Links</h4>
                    <ul class="space-y-2 text-green-600">
                        <li><a href="loginSignup.php" class="hover:text-green-800">Sign In</a></li>
                        <li><a href="#features" class="hover:text-green-800">Features</a></li>
                        <li><a href="#security" class="hover:text-green-800">Security</a></li>
                        <li><a href="#" class="hover:text-green-800">Documentation</a></li>
                    </ul>
                </div>
                
                <div>
                    <h4 class="font-semibold text-green-800 mb-4">Support</h4>
                    <ul class="space-y-2 text-green-600">
                        <li><a href="#" class="hover:text-green-800">Help Center</a></li>
                        <li><a href="#" class="hover:text-green-800">Contact Support</a></li>
                        <li><a href="#" class="hover:text-green-800">System Status</a></li>
                        <li><a href="#" class="hover:text-green-800">Privacy Policy</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="border-t border-green-200 mt-8 pt-8 text-center">
                <p class="text-green-600">
                    &copy; <?php echo date('Y'); ?> Government of the Republic of Zambia. All rights reserved.
                </p>
            </div>
        </div>
    </footer>

    <script>
        lucide.createIcons();
        
        function scrollToFeatures() {
            document.getElementById('features').scrollIntoView({ 
                behavior: 'smooth' 
            });
        }
        
        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all feature cards
        document.querySelectorAll('.neumorphic').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(el);
        });
    </script>
</body>
</html>