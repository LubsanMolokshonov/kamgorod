            </div>
        </main>
    </div>

    <?php if (isset($additionalJS) && !empty($additionalJS)): ?>
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <?php foreach ($additionalJS as $js): ?>
            <script src="<?php echo $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
