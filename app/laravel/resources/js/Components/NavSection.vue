<template>
  <div class="nav-section">
    <h3 class="section-title">{{ title }}</h3>
    <div class="section-items">
      <slot></slot>
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: {
    type: String,
    required: true
  }
});
</script>

<style scoped>
.nav-section {
  margin-bottom: 1.5rem;
  padding: 0 1rem;
}

.section-title {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  color: #6b7280;
  margin-bottom: 0.5rem;
  padding-left: 0.5rem;
  letter-spacing: 0.05em;
}

.section-items {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
</style> 