<script setup>
// Список виконаних тасок звіту (знімок або живий список із трекера).
defineProps({
  tasks: { type: Array, required: true },
});

// Кольори міток Trello; невідомий колір — сірий.
const LABEL_COLORS = {
  green: '#61bd4f',
  yellow: '#f2d600',
  orange: '#ff9f1a',
  red: '#eb5a46',
  purple: '#c377e0',
  blue: '#0079bf',
  sky: '#00c2e0',
  lime: '#51e898',
  pink: '#ff78cb',
  black: '#344563',
};
</script>

<template>
  <div v-if="!tasks.length" class="panel empty-panel">
    Тасок за цю дату немає.
  </div>

  <div v-else class="task-list">
    <div v-for="task in tasks" :key="task.id" class="panel task-card">
      <div class="task-main">
        <div class="task-title-row">
          <a :href="task.url" target="_blank" rel="noopener" class="task-title">{{ task.name }}</a>
          <span v-if="task.due_complete" class="task-status">Готово</span>
        </div>
        <div class="task-meta">
          <span v-if="task.list">{{ task.list }}</span>
          <span
            v-for="label in task.labels"
            :key="label.name + label.color"
            class="task-label"
            :style="{ backgroundColor: LABEL_COLORS[label.color] || '#b3bac5' }"
          >{{ label.name }}</span>
        </div>
        <div v-if="task.comment" class="task-comment">{{ task.comment }}</div>
      </div>
      <div v-if="task.start || task.due" class="task-time">
        <span v-if="task.start">{{ task.start }}</span>
        <span v-if="task.start && task.due"> — </span>
        <span v-if="task.due">{{ task.due }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.empty-panel {
  padding: 20px 16px;
  font-size: 13.5px;
  color: var(--muted);
  text-align: center;
}

.task-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.task-card {
  padding: 11px 14px;
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.task-main {
  flex: 1;
  min-width: 0;
}

.task-title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 2px;
}

.task-title {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--ink);
  text-decoration: none;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.task-title:hover {
  text-decoration: underline;
}

.task-status {
  font-size: 10px;
  font-weight: 700;
  padding: 1px 7px;
  border-radius: 0;
  background: var(--line);
  color: var(--ink);
  flex-shrink: 0;
}

.task-meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 11.5px;
  color: var(--muted);
}

.task-label {
  color: #fff;
  border-radius: 0;
  padding: 0 6px;
  font-size: 10px;
  font-weight: 600;
  line-height: 16px;
  overflow-wrap: anywhere;
}

/* Коментарі з таск-трекера часто містять довгі посилання — вони мають переноситись
   усередині картки, а не розтягувати сторінку. */
.task-comment {
  margin-top: 4px;
  font-size: 12px;
  color: var(--text-dim);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.task-time {
  text-align: right;
  flex-shrink: 0;
  font-size: 11px;
  color: var(--muted-2);
  font-variant-numeric: tabular-nums;
}

/* Довгий інтервал часу таски не лишає місця тексту на мобільній ширині —
   складаємо картку вертикально, час іде під назвою. align-items: stretch
   обов'язковий: із flex-start колонка отримує ширину найдовшого рядка назви
   і розсуває всю сторінку вшир. */
@media (max-width: 640px) {
  .task-card {
    flex-direction: column;
    align-items: stretch;
    gap: 4px;
  }

  /* На вузькому екрані обрізана назва майже нечитабельна — краще перенос */
  .task-title-row {
    align-items: flex-start;
  }

  .task-title {
    overflow: visible;
    white-space: normal;
    overflow-wrap: anywhere;
  }

  .task-time {
    text-align: left;
  }
}
</style>
