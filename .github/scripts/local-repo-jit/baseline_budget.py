"""Measured partition layout plus declared headroom; baseline-install proof only."""
from guest_capacity import MIN_ROOT_BYTES

GIB = 1073741824
BASE_SHA = '04e8f2461a16a44e78a370284471f29616cdd9baf1893df25807b71f985b6c94'
ROOT_START_BYTES = 1074790400
GPT_TAIL_BYTES = 16896
FILESYSTEM_HEADROOM_BYTES = 268435456
PROVISION_HEADROOM_BYTES = 536870912


def derive(root_start, minimum_root, filesystem_headroom, provision_headroom, gpt_tail):
    values = (root_start, minimum_root, filesystem_headroom, provision_headroom, gpt_tail)
    if any(type(value) is not int or value < 0 for value in values):
        raise ValueError('nonnegative integer capacity inputs required')
    required = sum(values)
    return ((required + GIB - 1) // GIB) * GIB


VIRTUAL_BYTES = derive(ROOT_START_BYTES, MIN_ROOT_BYTES, FILESYSTEM_HEADROOM_BYTES,
                       PROVISION_HEADROOM_BYTES, GPT_TAIL_BYTES)
