<style>
/* استایل فوتر */
.footer {
    background: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 25px 0;
    text-align: center;
    border-radius: 12px;
    margin-top: 30px;
    backdrop-filter: blur(10px);
    box-shadow: 0 -5px 20px rgba(0, 0, 0, 0.2);
}

.footer-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 15px;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

.footer-text {
    font-size: 1.1rem;
    font-weight: 500;
    color: #f8f9fa;
}

.footer-team {
    color: #4ecca3;
    font-weight: 700;
}

.footer-divider {
    width: 80%;
    max-width: 300px;
    height: 1px;
    background: rgba(255, 255, 255, 0.2);
    margin: 5px 0;
}

.footer-contact {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: #f8f9fa;
    text-decoration: none;
    font-size: 1.1rem;
    transition: all 0.3s ease;
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.footer-contact:hover {
    background: rgba(78, 204, 163, 0.2);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
}

.footer-contact i {
    color: #4ecca3;
    font-size: 1.2rem;
}

.footer-social {
    display: flex;
    gap: 15px;
    margin-top: 15px;
}

.social-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    text-decoration: none;
    transition: all 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.social-icon:hover {
    background: #4ecca3;
    transform: translateY(-3px);
}

.footer-copyright {
    margin-top: 20px;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.7);
}

/* رسپانسیو برای صفحه نمایش کوچک */
@media (max-width: 768px) {
    .footer-content {
        padding: 0 15px;
    }

    .footer-text {
        font-size: 1rem;
    }

    .footer-contact {
        font-size: 1rem;
        padding: 8px 16px;
    }

    .content {
        padding: 25px;
    }

    h1 {
        font-size: 2rem;
    }
}

@media (max-width: 480px) {
    .footer-contact {
        flex-direction: column;
        gap: 5px;
    }

    .footer-text {
        text-align: center;
        line-height: 1.6;
    }

    .footer {
        padding: 20px 0;
    }
}
</style>
<footer class="footer">
    <div class="footer-content">
        <div class="footer-text">
            Created With <span class="footer-team">Wehandle Team</span>
        </div>
        <div class="footer-divider"></div>
        <a href="tel:09176275262" class="footer-contact">
            <i class="fas fa-phone"></i>
            تماس و پشتیبانی: 09176275262
        </a>

        <div class="footer-copyright">
            © 2024 - تمامی حقوق محفوظ است
        </div>
    </div>
</footer>