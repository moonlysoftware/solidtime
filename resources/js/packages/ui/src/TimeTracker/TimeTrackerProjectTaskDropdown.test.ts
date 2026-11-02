/* eslint-disable vue/one-component-per-file */
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, onMounted } from 'vue';
import TimeTrackerProjectTaskDropdown from './TimeTrackerProjectTaskDropdown.vue';
import type { Client, Project, Task } from '@/packages/api/src';

const DropdownStub = defineComponent({
    props: {
        modelValue: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['update:modelValue'],
    setup(_, { emit, slots }) {
        onMounted(() => emit('update:modelValue', true));
        return () => h('div', [slots.trigger?.(), slots.content?.()]);
    },
});

const FocusTrapStub = defineComponent({
    setup(_, { slots }) {
        return () => h('div', slots.default?.());
    },
});

function mountDropdown(props: Record<string, unknown> = {}) {
    return mount(TimeTrackerProjectTaskDropdown, {
        props: {
            project: null,
            task: null,
            projects: [] as Project[],
            tasks: [] as Task[],
            clients: [] as Client[],
            createProject: vi.fn(),
            createClient: vi.fn(),
            currency: 'EUR',
            enableEstimatedTime: false,
            organizationBillableRate: null,
            canCreateProject: false,
            ...props,
        },
        global: {
            stubs: {
                Dropdown: DropdownStub,
                UseFocusTrap: FocusTrapStub,
            },
        },
    });
}

async function openDropdown() {
    const wrapper = mountDropdown();
    await nextTick();
    await nextTick();
    return wrapper;
}

describe('TimeTrackerProjectTaskDropdown', () => {
    it('groups suggestions into the normal expandable project/task tree with ranked tasks', async () => {
        const project = {
            id: 'p-1',
            name: 'Portal',
            color: '#fff',
            client_id: null,
            is_archived: false,
        } as unknown as Project;
        const tasks = [
            { id: 't-design', name: 'Figma Designs', project_id: 'p-1', is_done: false },
            { id: 't-api', name: 'Suppliers API', project_id: 'p-1', is_done: false },
        ] as unknown as Task[];
        const suggestion = {
            project_id: 'p-1',
            project_name: 'Portal',
            task_id: 't-api',
            task_name: 'Suppliers API',
        };
        const suggest = vi.fn().mockResolvedValue([suggestion]);
        const wrapper = mountDropdown({
            description: 'Fix sign-in',
            suggest,
            projects: [project],
            tasks,
        });
        await flushPromises();
        expect(suggest).toHaveBeenCalledWith('Fix sign-in', null);
        expect(wrapper.emitted('changed')).toBeUndefined();
        expect(wrapper.text()).toContain('Suggested');
        const rows = wrapper.findAll('[data-project-id="p-1"]');
        expect(rows).toHaveLength(1);
        await rows[0]!.find('button').trigger('click');
        const taskRows = wrapper.findAll('[data-task-id]');
        expect(taskRows.map((row) => row.attributes('data-task-id'))).toEqual([
            't-api',
            't-design',
        ]);
        await taskRows[0]!.trigger('click');
        expect(wrapper.emitted('changed')?.at(-1)).toEqual(['p-1', 't-api']);
    });

    it('shows suggestions when project data finishes loading after the response', async () => {
        const suggest = vi
            .fn()
            .mockResolvedValue([
                { project_id: 'p-1', project_name: 'Portal', task_id: null, task_name: null },
            ]);
        const wrapper = mountDropdown({ description: 'Fix sign-in', suggest });
        await flushPromises();
        expect(wrapper.text()).not.toContain('Suggested');
        await wrapper.setProps({
            projects: [
                {
                    id: 'p-1',
                    name: 'Portal',
                    color: '#fff',
                    client_id: null,
                    is_archived: false,
                } as unknown as Project,
            ],
        });
        await flushPromises();
        expect(wrapper.text()).toContain('Suggested');
        expect(wrapper.findAll('[data-project-id="p-1"]')).toHaveLength(1);
        wrapper.unmount();
    });

    it('passes the current entry ID to exclude its own historical example', async () => {
        const suggest = vi.fn().mockResolvedValue([]);
        const wrapper = mountDropdown({
            description: 'Fix sign-in',
            timeEntryId: 'entry-1',
            suggest,
        });
        await flushPromises();
        expect(suggest).toHaveBeenCalledWith('Fix sign-in', 'entry-1');
        wrapper.unmount();
    });

    it('keeps the normal picker available when suggestions fail', async () => {
        const wrapper = mountDropdown({
            description: 'Fix sign-in',
            suggest: vi.fn().mockRejectedValue(new Error('Gateway unavailable')),
        });
        await flushPromises();
        expect(wrapper.text()).not.toContain('Suggested');
        await wrapper.find('[data-project-id=""]').trigger('click');
        expect(wrapper.emitted('changed')?.at(-1)).toEqual(['', null]);
    });

    it('keeps the existing empty-string no-project value by default', async () => {
        const wrapper = await openDropdown();

        await wrapper.find('[data-project-id=""]').trigger('click');

        expect(wrapper.emitted('update:project')?.at(-1)).toEqual(['']);
        expect(wrapper.emitted('changed')?.at(-1)).toEqual(['', null]);
    });

    it('can emit null for no-project consumers that use null as the domain value', async () => {
        const wrapper = mountDropdown({ project: 'p-1', noProjectValue: null });
        await nextTick();
        await nextTick();

        await wrapper.find('[data-project-id=""]').trigger('click');

        expect(wrapper.emitted('update:project')?.at(-1)).toEqual([null]);
        expect(wrapper.emitted('changed')?.at(-1)).toEqual([null, null]);
    });

    it('still exposes "No Project" when projects are empty and project creation is allowed', async () => {
        const wrapper = mountDropdown({ canCreateProject: true });
        await nextTick();
        await nextTick();

        await wrapper.find('[data-project-id=""]').trigger('click');

        expect(wrapper.emitted('changed')?.at(-1)).toEqual(['', null]);
    });

    it("keeps a project's tasks visible when the search term matches the project name", async () => {
        const project = {
            id: 'p-dummy',
            name: 'dummy',
            color: '#fff',
            client_id: null,
            is_archived: false,
        } as unknown as Project;
        const tasks = [
            { id: 't-1', name: 'design', project_id: 'p-dummy', is_done: false },
            { id: 't-2', name: 'build', project_id: 'p-dummy', is_done: false },
        ] as unknown as Task[];

        const wrapper = mountDropdown({ projects: [project], tasks });
        await nextTick();
        await nextTick();

        const searchInput = wrapper.find('[data-testid="client_dropdown_search"]');
        await searchInput.setValue('dummy');
        await nextTick();

        // project itself shows up
        expect(wrapper.find('[data-project-id="p-dummy"]').exists()).toBe(true);
        // and its tasks are still available even though they don't match "dummy":
        // the task expander keeps showing all of the project's tasks
        expect(wrapper.text()).toContain('2 Tasks');
    });
});
