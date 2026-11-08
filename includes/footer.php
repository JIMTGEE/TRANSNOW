<?php
// includes/footer.php
// Common footer template for all pages
// Includes copyright, links, and script includes
?>

</div> <!-- Close main content wrapper from header -->

<footer class="bg-light text-center text-lg-start mt-auto">
    <div class="container p-4">
        <div class="row">
            <div class="col-lg-6 col-md-12 mb-4 mb-md-0">
                <h5 class="text-uppercase">Jobwall</h5>
                <p>
                    A job portal dedicated to Chinese students and others seeking opportunities worldwide.
                </p>
            </div>
            <div class="col-lg-3 col-md-6 mb-4 mb-md-0">
                <h5 class="text-uppercase">Links</h5>
                <ul class="list-unstyled">
                    <li><a href="/about.php">About Us</a></li>
                    <li><a href="/contact.php">Contact</a></li>
                    <li><a href="/privacy.php">Privacy Policy</a></li>
                    <li><a href="/terms.php">Terms of Service</a></li>
                </ul>
            </div>
            <div class="col-lg-3 col-md-6 mb-4 mb-md-0">
                <h5 class="text-uppercase">Follow Us</h5>
                <ul class="list-unstyled">
                    <li><a href="#"><i class="fab fa-facebook-f"></i> Facebook</a></li>
                    <li><a href="#"><i class="fab fa-twitter"></i> Twitter</a></li>
                    <li><a href="#"><i class="fab fa-linkedin-in"></i> LinkedIn</a></li>
                    <li><a href="#"><i class="fab fa-weixin"></i> WeChat</a></li> <!-- For Chinese users -->
                </ul>
            </div>
        </div>
    </div>
    <div class="text-center p-3" style="background-color: rgba(0, 0, 0, 0.2);">
        © <?php echo date('Y'); ?> Jobwall. All rights reserved.
    </div>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom JS -->
<script src="/assets/js/script.js"></script>

</body>
</html>