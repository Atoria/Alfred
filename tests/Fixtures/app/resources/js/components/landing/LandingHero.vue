<script setup lang="ts">
defineProps<{ loginUrl: string }>();
</script>

<template>
    <section class="hero">
        <h1 class="heading">
            Track every click.<br>
            <span class="heading-accent">Know what works.</span>
        </h1>
    </section>
</template>

<style scoped>
.heading { font-weight: 800; color: var(--text-1); }
.heading-accent { color: var(--brand); }
</style>
