  tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'gov-blue': '#1e40af',
                        'gov-navy': '#0f172a',
                        'gov-gray': '#64748b',
                        'success': '#10b981',
                        'warning': '#f59e0b',
                        'danger': '#ef4444'
                    },
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif']
                    },
                    spacing: {
                        '18': '4.5rem',
                        '88': '22rem'
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-in-out',
                        'slide-up': 'slideUp 0.3s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite'
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' }
                        },
                        slideUp: {
                            '0%': { opacity: '0', transform: 'translateY(20px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' }
                        }
                    },
                    boxShadow: {
                        'soft': '0 2px 15px -3px rgba(0, 0, 0, 0.07), 0 10px 20px -2px rgba(0, 0, 0, 0.04)',
                        'elegant': '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)',
                        'card': '0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06)'
                    },
                    backdropBlur: {
                        xs: '2px'
                    }
                }
            }
        }
        // Enhanced DOM interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Smooth scrolling for navigation links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });

             const profileButton = document.getElementById('profileButton');
    const profileDropdown = document.getElementById('profileDropdown');

    profileButton.addEventListener('click', () => {
        profileDropdown.classList.toggle('hidden');
    });

    // Close dropdown when clicking outside
    window.addEventListener('click', (e) => {
        if (!profileButton.contains(e.target) && !profileDropdown.contains(e.target)) {
            profileDropdown.classList.add('hidden');
        }
    });
            // Form enhancement
            const inputs = document.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.classList.add('modern-input', 'transition-all', 'duration-200');
                
                // Add floating label effect
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });
                
                input.addEventListener('blur', function() {
                    if (!this.value) {
                        this.parentElement.classList.remove('focused');
                    }
                });
            });

            // Button enhancement
            const buttons = document.querySelectorAll('button');
            buttons.forEach(button => {
                if (button.classList.contains('bg-gradient-to-r')) {
                    button.classList.add('btn-primary');
                } else if (button.classList.contains('bg-gray-50') || button.classList.contains('border')) {
                    button.classList.add('btn-secondary');
                }
                
                button.classList.add('micro-bounce', 'transition-all', 'duration-200');
            });

            // Enhanced table interactions
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.classList.add('transform', 'scale-[1.01]');
                });
                
                row.addEventListener('mouseleave', function() {
                    this.classList.remove('transform', 'scale-[1.01]');
                });
            });

            // Dynamic status indicators
            const statusElements = document.querySelectorAll('[class*="status-"]');
            statusElements.forEach(element => {
                element.classList.add('status-pulse');
            });

            // Card hover effects
            const cards = document.querySelectorAll('.hover-lift');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-4px) scale(1.02)';
                    this.style.boxShadow = '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = '';
                });
            });

            // Progressive enhancement for animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate-fade-in');
                    }
                });
            }, observerOptions);

            // Observe all main sections
            document.querySelectorAll('section').forEach(section => {
                observer.observe(section);
            });

            // Enhanced search functionality
            const searchInputs = document.querySelectorAll('input[type="search"]');
            searchInputs.forEach(input => {
                input.addEventListener('input', function() {
                    // Add real-time search visual feedback
                    this.classList.add('ring-2', 'ring-blue-200');
                    
                    setTimeout(() => {
                        this.classList.remove('ring-2', 'ring-blue-200');
                    }, 300);
                });
            });

            // Notification system simulation
            function showNotification(message, type = 'info') {
                const notification = document.createElement('div');
                notification.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg transform translate-x-full transition-transform duration-300 ${
                    type === 'success' ? 'bg-green-500 text-white' :
                    type === 'warning' ? 'bg-yellow-500 text-white' :
                    type === 'error' ? 'bg-red-500 text-white' :
                    'bg-blue-500 text-white'
                }`;
                notification.textContent = message;
                
                document.body.appendChild(notification);
                
                setTimeout(() => {
                    notification.classList.remove('translate-x-full');
                }, 100);
                
                setTimeout(() => {
                    notification.classList.add('translate-x-full');
                    setTimeout(() => {
                        document.body.removeChild(notification);
                    }, 300);
                }, 3000);
            }

            // Form submission simulation
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    showNotification('Request submitted successfully!', 'success');
                });
            });

            // Quick action buttons
            const quickActionBtns = document.querySelectorAll('.bg-gradient-to-r');
            quickActionBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    if (this.textContent.includes('New Data Request')) {
                        showNotification('Opening new request form...', 'info');
                    } else if (this.textContent.includes('View All Requests')) {
                        showNotification('Loading request history...', 'info');
                    } else if (this.textContent.includes('Generate Report')) {
                        showNotification('Preparing analytics report...', 'info');
                    }
                });
            });
        });